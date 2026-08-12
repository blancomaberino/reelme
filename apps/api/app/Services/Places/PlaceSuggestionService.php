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
     * @param  array<string, mixed>  $patch  field => proposed value
     *
     * @throws ValidationException when the patch changes nothing
     */
    public function submit(Place $place, User $user, array $patch): PlaceEditSuggestion
    {
        // Only ever the suggestion allow-list — narrower than PlaceEditor's own
        // curated set, and applied here rather than trusted from the request so
        // Filament, a console command or a future importer cannot widen it by
        // calling with a fuller patch.
        $patch = array_intersect_key($patch, array_flip(PlaceEditSuggestion::FIELDS));
        $changes = $this->editor->diff($place, $patch);

        if ($changes === []) {
            // An unchanged form is not a moderation task. Refused rather than
            // filed, because a queue of no-op rows is a queue nobody triages.
            throw ValidationException::withMessages([
                'changes' => 'This suggestion does not change anything.',
            ]);
        }

        $isOwner = $user->ownsPlace($place);

        if ($isOwner) {
            return $this->applyAsOwner($place, $user, $patch, $changes);
        }

        // One open proposal per person per place — enforced by a partial unique
        // index, so this updateOrCreate is a convenience and not the guarantee.
        // Re-submitting supersedes: someone correcting their own typo should not
        // have to wait for the first attempt to be rejected.
        return PlaceEditSuggestion::query()->updateOrCreate(
            [
                'place_id' => $place->id,
                'user_id' => $user->id,
                'status' => SuggestionStatus::Pending,
            ],
            ['changes' => $changes, 'is_owner_submission' => false],
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
        $this->assertPending($suggestion);

        return DB::transaction(function () use ($suggestion, $reviewer): PlaceEditSuggestion {
            $place = $suggestion->place;

            $edit = $place === null ? null : $this->editor->apply(
                $place,
                $suggestion->patch(),
                // A human accepted this, so it locks the fields it changes for the
                // same reason a Filament edit does: enrichment must not undo a
                // correction a person made on purpose.
                PlaceEdit::ORIGIN_MANUAL,
                $reviewer->id,
                "Approved suggestion #{$suggestion->id}",
            );

            $suggestion->forceFill([
                'status' => SuggestionStatus::Approved,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'place_edit_id' => $edit?->id,
            ])->save();

            return $suggestion;
        });
    }

    /** Decline a pending suggestion, recording why. */
    public function reject(PlaceEditSuggestion $suggestion, User $reviewer, ?string $reason = null): PlaceEditSuggestion
    {
        $this->assertPending($suggestion);

        $suggestion->forceFill([
            'status' => SuggestionStatus::Rejected,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
            'reason' => $reason,
        ])->save();

        return $suggestion;
    }

    /**
     * A decision can only be made once.
     *
     * The guard lives here rather than only on the Filament action's `visible()`,
     * because re-approving a settled row is not a harmless repeat: `approve()`
     * re-diffs against the place as it is NOW, so approving a year-old proposal
     * a second time would write its values back over whatever corrected them
     * since — and record a fresh audit row saying a human meant to.
     *
     * @throws ValidationException
     */
    private function assertPending(PlaceEditSuggestion $suggestion): void
    {
        if (! $suggestion->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'This suggestion has already been decided.',
            ]);
        }
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
