<?php

namespace App\Services\Places;

use App\Enums\SuggestionStatus;
use App\Models\Place;
use App\Models\PlaceEdit;
use App\Models\PlaceEditSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Suggested edits to a place's business info (T-083) — submit, approve, reject.
 *
 * Everything that ever writes a place goes through {@see PlaceEditor}: the
 * operator's own edit applying on submit, and a moderator approving a stranger's
 * proposal, are the SAME call with a different `note`. That is the whole design.
 * Two apply paths would be two field allow-lists, two audit shapes and two
 * places to forget the manual-override lock — and the one that drifted would be
 * the one nobody exercises, since the owner path is rare in test data and common
 * in production.
 *
 * What differs between the two is only *who decided*, which is exactly what the
 * suggestion row records.
 */
class PlaceSuggestionService
{
    public function __construct(private readonly PlaceEditor $editor) {}

    /**
     * Take a proposed patch from a signed-in user.
     *
     * A verified operator's proposal ({@see User::ownsPlace()}) applies
     * immediately and is filed as already-approved; everyone else's queues.
     *
     * A `$note` (T-112) is the "something else is wrong" box — free prose for
     * everything the five-field form cannot express. It makes a submission
     * substantive on its own: a note with no field change is a valid proposal,
     * and only a submission carrying NEITHER is refused.
     *
     * @param  array<string, mixed>  $patch  field => proposed value
     * @param  string|null  $note  free text, already trimmed to null when blank
     *
     * @throws ValidationException when the submission carries nothing at all
     */
    public function submit(Place $place, User $user, array $patch, ?string $note = null): PlaceEditSuggestion
    {
        // Only ever the suggestion allow-list — narrower than PlaceEditor's own
        // curated set, and applied here rather than trusted from the request so
        // Filament, a console command or a future importer cannot widen it by
        // calling with a fuller patch.
        $patch = array_intersect_key($patch, array_flip(PlaceEditSuggestion::FIELDS));
        $changes = $this->editor->diff($place, $patch);

        if ($changes === [] && $note === null) {
            // An unchanged form with nothing written on it is not a moderation
            // task. Refused rather than filed, because a queue of no-op rows is
            // a queue nobody triages. A NOTE alone is a different matter — it is
            // the whole finding for "this place closed down".
            throw ValidationException::withMessages([
                'changes' => 'This suggestion does not change anything.',
            ]);
        }

        $isOwner = $user->ownsPlace($place);

        // The operator's fast path is for FIELD edits only. A note asks a human
        // for something — to check whether the place really closed, to fix what
        // the form cannot reach — and auto-approving the row it rides on would
        // file that question as already answered, in a state the queue does not
        // show by default. So a note queues no matter who wrote it; the row keeps
        // `is_owner_submission`, which is how the moderator knows it came from
        // the venue itself. An operator who only wants to edit their own fields
        // leaves the box empty and keeps the instant save.
        if ($isOwner && $note === null) {
            return $this->applyAsOwner($place, $user, $patch, $changes);
        }

        // One open proposal per person per place — enforced by a partial unique
        // index, so this updateOrCreate is a convenience and not the guarantee.
        // Re-submitting supersedes: someone correcting their own typo should not
        // have to wait for the first attempt to be rejected.
        //
        // The double-submit race needs nothing here: `updateOrCreate` delegates
        // to `createOrFirst`, which already catches the unique violation (inside
        // a savepoint, so an enclosing transaction survives) and re-reads the
        // winning row. Hand-rolling that retry would duplicate the framework and
        // skip the `fill()` that makes the second submit supersede the first.
        return PlaceEditSuggestion::query()->updateOrCreate(
            [
                'place_id' => $place->id,
                'user_id' => $user->id,
                'status' => SuggestionStatus::Pending,
            ],
            ['changes' => $changes, 'note' => $note, 'is_owner_submission' => $isOwner],
        );
    }

    /**
     * Approve a pending suggestion: apply it, and record what that produced.
     *
     * Deliberately re-applied through {@see PlaceEditor} rather than trusting the
     * stored diff — the place may have moved since the proposal was written, and
     * `apply()` compares against the row as it is NOW. A field somebody already
     * fixed simply drops out of the audit trail instead of being written twice.
     * If everything has already been fixed, the approval still settles the row;
     * `place_edit_id` stays null, which is the honest record of "accepted,
     * changed nothing".
     */
    public function approve(PlaceEditSuggestion $suggestion, User $reviewer): PlaceEditSuggestion
    {
        return DB::transaction(function () use ($suggestion, $reviewer): PlaceEditSuggestion {
            $locked = $this->lockPending($suggestion);

            if ($locked->isNoteOnly()) {
                // Nothing to apply, so "approved" would be a claim about an edit
                // that never happened — green in the queue, null `place_edit_id`,
                // and no record anywhere of what was actually done about "this
                // place closed down". {@see action()} is the verb for these.
                throw ValidationException::withMessages([
                    'status' => 'This suggestion has no field change to apply. Use Actioned instead.',
                ]);
            }

            $place = $locked->place;

            $edit = $place === null ? null : $this->editor->apply(
                $place,
                $locked->patch(),
                // A human accepted this, so it locks the fields it changes for the
                // same reason a Filament edit does: enrichment must not undo a
                // correction a person made on purpose.
                PlaceEdit::ORIGIN_MANUAL,
                $reviewer->id,
                "Approved suggestion #{$locked->id}",
            );

            $locked->forceFill([
                'status' => SuggestionStatus::Approved,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'place_edit_id' => $edit?->id,
            ])->save();

            return $locked;
        });
    }

    /** Decline a pending suggestion, recording why. */
    public function reject(PlaceEditSuggestion $suggestion, User $reviewer, string $reason): PlaceEditSuggestion
    {
        return DB::transaction(function () use ($suggestion, $reviewer, $reason): PlaceEditSuggestion {
            $locked = $this->lockPending($suggestion);

            $locked->forceFill([
                'status' => SuggestionStatus::Rejected,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'reason' => $reason,
            ])->save();

            return $locked;
        });
    }

    /**
     * Settle a note-only proposal: the moderator dealt with it by hand (T-112).
     *
     * "This place closed down" has no patch to apply — what resolves it is a
     * person going and doing something (correcting a field the form cannot
     * reach, hiding the place, or deciding it needed nothing). This records that
     * it was done and what was done, which is the part that would otherwise be
     * lost: `approve` would claim an edit that never happened, and `reject`
     * would say the submitter was wrong when they were right.
     *
     * Deliberately refused on a row that still carries an applicable field
     * patch. Otherwise Actioned becomes the one-click way to make any awkward
     * row go away, and a real correction settles green with nothing written to
     * the place — the exact failure the queue exists to prevent.
     *
     * @param  string  $note  what the moderator actually did, recorded on `reason`
     *
     * @throws ValidationException
     */
    public function action(PlaceEditSuggestion $suggestion, User $reviewer, string $note): PlaceEditSuggestion
    {
        return DB::transaction(function () use ($suggestion, $reviewer, $note): PlaceEditSuggestion {
            $locked = $this->lockPending($suggestion);

            // `isNoteOnly()`, not `patch() === []` — the same predicate
            // `approve()` refuses on and the same one the Filament button is
            // gated by, so the rule "Actioned is for note-only rows" is true in
            // one place instead of approximately true in three. The looser
            // check also let through a row with NO patch and NO note (not from
            // `submit()`, but reachable from a seeder, an import or a console
            // command), which would settle as Actioned with no finding recorded
            // anywhere — a decision about nothing.
            if (! $locked->isNoteOnly()) {
                throw ValidationException::withMessages([
                    'status' => 'This suggestion is not a note-only proposal. Approve or reject it instead.',
                ]);
            }

            $locked->forceFill([
                'status' => SuggestionStatus::Actioned,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                // Same column as a rejection's reason: both are the reviewer's
                // one written record of how this row was settled, and splitting
                // them into two nullable text columns would mean every renderer
                // had to know which verb it was looking at to find the words.
                'reason' => $note,
            ])->save();

            return $locked;
        });
    }

    /**
     * Take the row's decision lock and confirm it is still undecided.
     *
     * A decision can only be made once. The guard lives here rather than only on
     * the Filament action's `visible()`, because re-deciding a settled row is not
     * a harmless repeat: `approve()` re-diffs against the place as it is NOW, so
     * approving a year-old proposal a second time would write its values back
     * over whatever corrected them since — and record a fresh audit row saying a
     * human meant to.
     *
     * The check reads the LOCKED row rather than the caller's instance, and the
     * caller runs it inside a transaction. Checking `$suggestion->isPending()`
     * from memory and then writing is a read-then-write straddling no lock: two
     * moderators clicking Approve and Reject in the same second both pass the
     * guard, and the row ends up rejected with the place already patched — a
     * rejection that changed the place. Same reasoning, and the same mechanism,
     * as {@see PlaceEditor::apply()}'s locked refetch (T-085).
     *
     * @throws ValidationException
     */
    private function lockPending(PlaceEditSuggestion $suggestion): PlaceEditSuggestion
    {
        $locked = PlaceEditSuggestion::query()
            ->whereKey($suggestion->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked === null || ! $locked->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'This suggestion has already been decided.',
            ]);
        }

        return $locked;
    }

    /**
     * The operator's own edit: applied on submit, filed as its own approval.
     *
     * The row exists even though nothing queued, so a venue's history is ONE
     * list — "who changed this, and did anyone review it" answered the same way
     * for every change. The alternative (owner edits leave only a `place_edits`
     * row) makes the two halves of the same question live in two tables.
     *
     * @param  array<string, mixed>  $patch
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    private function applyAsOwner(Place $place, User $owner, array $patch, array $changes): PlaceEditSuggestion
    {
        return DB::transaction(function () use ($place, $owner, $patch, $changes): PlaceEditSuggestion {
            $edit = $this->editor->apply(
                $place,
                $patch,
                PlaceEdit::ORIGIN_MANUAL,
                $owner->id,
                'Operator edit',
            );

            return PlaceEditSuggestion::query()->create([
                'place_id' => $place->id,
                'user_id' => $owner->id,
                'changes' => $changes,
                'status' => SuggestionStatus::Approved,
                'is_owner_submission' => true,
                'reviewed_by_user_id' => $owner->id,
                'reviewed_at' => now(),
                'place_edit_id' => $edit?->id,
            ]);
        });
    }
}
