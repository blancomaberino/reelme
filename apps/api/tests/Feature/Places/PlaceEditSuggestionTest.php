<?php

use App\Enums\PlaceStatus;
use App\Enums\SuggestionStatus;
use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\PlaceEdit;
use App\Models\PlaceEditSuggestion;
use App\Models\User;
use App\Services\Places\PlaceSuggestionService;
use Illuminate\Validation\ValidationException;

/** A viewport over Montevideo, for the map-pin regressions below. */
const MONTEVIDEO_BBOX = '-56.30,-35.00,-56.00,-34.80';

/**
 * Suggested edits to a place's business info (T-083).
 *
 * The organising property: **there is exactly one write path.** An operator
 * editing their own venue and a moderator approving a stranger's proposal are
 * the same {@see PlaceEditor} call, so the field allow-list, the manual-override
 * lock and the audit trail cannot differ between them. Most of what is asserted
 * below is that sameness — and the places where the two genuinely differ (who
 * decided, and whether anything queued).
 */
describe('suggesting an edit', function () {
    it('queues a stranger\'s correction without touching the place', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/suggestions", ['phone' => '+598 2 900 0000'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_owner_submission', false)
            ->assertJsonPath('data.changes.0.field', 'phone')
            ->assertJsonPath('data.changes.0.from', '+598 2 111 1111')
            ->assertJsonPath('data.changes.0.to', '+598 2 900 0000');

        // The point of queueing: the place is untouched until someone decides.
        expect($place->fresh()->phone)->toBe('+598 2 111 1111')
            ->and(PlaceEdit::query()->where('place_id', $place->id)->count())->toBe(0);
    });

    it('applies a verified operator\'s own edit immediately, and files it as approved', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
        $owner = operatorOfPlace($place);

        $this->actingAs($owner)
            ->postJson("/api/v1/places/{$place->id}/suggestions", [
                'phone' => '+598 2 900 0000',
                'website' => 'https://cantina.uy',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_owner_submission', true);

        $place->refresh();
        expect($place->phone)->toBe('+598 2 900 0000')
            ->and($place->website)->toBe('https://cantina.uy')
            // The same audit trail a curator's Filament edit writes, from the
            // same service — an operator edit is not a second, quieter path.
            ->and(PlaceEdit::query()->where('place_id', $place->id)->count())->toBe(1)
            // And it LOCKS what it changed: enrichment must not undo an operator
            // correcting their own phone number an hour later.
            ->and($place->isFieldLocked('phone'))->toBeTrue()
            ->and($place->isFieldLocked('website'))->toBeTrue();
    });

    it('does not let an operator edit a venue they do not run', function () {
        $owner = operatorOfPlace(Place::factory()->create());
        // The flag the product calls "business owner" — set here on purpose, so
        // the assertion below is about the CLAIM and not about a missing flag.
        $owner->update(['is_restaurant_owner' => true]);
        $other = Place::factory()->create(['phone' => '+598 2 111 1111']);

        // `is_restaurant_owner` is true for this user — the flag says they run
        // SOMETHING, and if it were the gate they would run everything.
        $this->actingAs($owner)
            ->postJson("/api/v1/places/{$other->id}/suggestions", ['phone' => '+598 2 900 0000'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        expect($other->fresh()->phone)->toBe('+598 2 111 1111');
    });

    it('stops applying directly the moment the claim stops being verified', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
        $owner = operatorOfPlace($place);

        PlaceClaim::query()->where('user_id', $owner->id)->update(['status' => 'rejected']);

        $this->actingAs($owner)
            ->postJson("/api/v1/places/{$place->id}/suggestions", ['phone' => '+598 2 900 0000'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        expect($place->fresh()->phone)->toBe('+598 2 111 1111');
    });

    it('refuses a form that changes nothing', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111', 'city' => 'Montevideo']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/suggestions", [
                'phone' => '+598 2 111 1111',
                'city' => 'Montevideo',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.changes.0', 'This suggestion does not change anything.');

        expect(PlaceEditSuggestion::query()->count())->toBe(0);
    });

    it('records only the fields that actually differ', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111', 'city' => 'Montevideo']);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/suggestions", [
                // Unchanged — must not become a "change" a moderator has to read.
                'city' => 'Montevideo',
                'phone' => '+598 2 900 0000',
            ])
            ->assertCreated()
            ->assertJsonCount(1, 'data.changes')
            ->assertJsonPath('data.changes.0.field', 'phone');
    });

    it('supersedes the submitter\'s own open proposal instead of filing a second', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
        $user = User::factory()->create();

        $first = $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/suggestions", ['phone' => '+598 2 900 0000'])
            ->assertCreated()->json('data.id');

        $second = $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/suggestions", ['phone' => '+598 2 900 0001'])
            ->assertCreated()->json('data.id');

        expect($second)->toBe($first)
            ->and(PlaceEditSuggestion::query()->where('place_id', $place->id)->count())->toBe(1)
            ->and(PlaceEditSuggestion::query()->first()->patch())->toBe(['phone' => '+598 2 900 0001']);
    });

    it('keeps a settled proposal in history when the same user files a new one', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
        $user = User::factory()->create();
        PlaceEditSuggestion::factory()->rejected()->create([
            'place_id' => $place->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/suggestions", ['phone' => '+598 2 900 0000'])
            ->assertCreated();

        // Two rows: the partial unique index covers PENDING only, so history
        // survives and the person is not locked out by their own rejected try.
        expect(PlaceEditSuggestion::query()->where('place_id', $place->id)->count())->toBe(2);
    });

    it('requires a signed-in user', function () {
        $place = Place::factory()->create();

        $this->postJson("/api/v1/places/{$place->id}/suggestions", ['phone' => '+598 2 900 0000'])
            ->assertUnauthorized();
    });

    it('404s on a merged tombstone rather than collecting corrections nobody sees', function () {
        $survivor = Place::factory()->create();
        $merged = Place::factory()->create([
            'status' => PlaceStatus::Merged,
            'merged_into_place_id' => $survivor->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$merged->id}/suggestions", ['phone' => '+598 2 900 0000'])
            ->assertNotFound();
    });
});

/**
 * "Something else is wrong" (T-112).
 *
 * The five-field form cannot say "this place closed down", and the only other
 * free-text box on that screen files a REPORT — a moderation event against the
 * venue, triaged with take-down and ban. So the note rides the suggestion, and
 * what has to hold is: a note alone is a real proposal, an empty submission is
 * still refused, and a note never settles as an edit that never happened.
 */
describe('a free-text note', function () {
    it('queues a note on its own, with an empty diff', function () {
        $place = Place::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/suggestions", [
                'note' => 'This place closed down last month.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.note', 'This place closed down last month.')
            // `changes` is NOT NULL, so a note-only row stores `{}` — every
            // renderer downstream has to survive that.
            ->assertJsonCount(0, 'data.changes');

        $suggestion = PlaceEditSuggestion::query()->sole();
        expect($suggestion->getAttribute('changes'))->toBe([])
            ->and($suggestion->isNoteOnly())->toBeTrue();
    });

    it('carries a note alongside field changes', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111']);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/suggestions", [
                'phone' => '+598 2 900 0000',
                'note' => 'The prices on the menu photo are two years old.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.changes.0.field', 'phone')
            ->assertJsonPath('data.note', 'The prices on the menu photo are two years old.');

        expect(PlaceEditSuggestion::query()->sole()->isNoteOnly())->toBeFalse();
    });

    it('still refuses a submission carrying neither a change nor a note', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111']);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/suggestions", [
                'phone' => '+598 2 111 1111',
                // Whitespace is not something written: the trim happens before
                // the "does this carry anything" question is asked.
                'note' => '   ',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.changes.0', 'This suggestion does not change anything.');

        expect(PlaceEditSuggestion::query()->count())->toBe(0);
    });

    /**
     * The limit is written down THREE times — the PHP const, `maxLength` in
     * place-edit-suggestion.json, and the mobile field's `maxLength` prop — and
     * nothing else in the build compares them. A boundary test derived from
     * `NOTE_MAX` moves with the const, so it stays green while the schema (and
     * therefore every generated client) keeps promising the old number.
     */
    it('agrees with the contract schema on how long a note may be', function () {
        $path = config('contracts.schemas_path').'/place-edit-suggestion.json';
        $schema = json_decode((string) file_get_contents($path), true);

        expect($schema['properties']['note']['maxLength'] ?? null)->toBe(PlaceEditSuggestion::NOTE_MAX)
            // Pinned to the literal as well, because both sides moving together
            // to a wrong number is still drift — this is the value `reports.
            // details` uses, and the two boxes sit on the same screen.
            ->and(PlaceEditSuggestion::NOTE_MAX)->toBe(2000);
    });

    it('bounds the note at the same length as a report\'s details', function () {
        $place = Place::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/suggestions", [
                'note' => str_repeat('a', PlaceEditSuggestion::NOTE_MAX + 1),
            ])
            ->assertStatus(422);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/suggestions", [
                'note' => str_repeat('a', PlaceEditSuggestion::NOTE_MAX),
            ])
            ->assertCreated();
    });

    /**
     * The decision this task took explicitly. An operator's FIELD edit applies
     * on submit — but a note asks a human for something, and auto-approving the
     * row it rides on would file that question as already answered, in a state
     * the queue does not show by default. So the note queues no matter who wrote
     * it, and the row still says it came from the venue.
     */
    it('queues a verified operator\'s note instead of auto-approving it', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
        $owner = operatorOfPlace($place);

        $this->actingAs($owner)
            ->postJson("/api/v1/places/{$place->id}/suggestions", [
                'phone' => '+598 2 900 0000',
                'note' => 'We also moved to the corner unit — the pin is wrong.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            // Still flagged as theirs, which is how the moderator knows this is
            // the venue talking and not a passer-by.
            ->assertJsonPath('data.is_owner_submission', true);

        // Nothing applied: the whole submission waits, patch included.
        expect($place->fresh()->phone)->toBe('+598 2 111 1111')
            ->and(PlaceEdit::query()->where('place_id', $place->id)->count())->toBe(0);
    });

    it('keeps the operator\'s instant save when they write no note', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
        $owner = operatorOfPlace($place);

        $this->actingAs($owner)
            ->postJson("/api/v1/places/{$place->id}/suggestions", ['phone' => '+598 2 900 0000'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved');

        expect($place->fresh()->phone)->toBe('+598 2 900 0000');
    });

    it('supersedes the submitter\'s own open note rather than filing a second', function () {
        $place = Place::factory()->create();
        $user = User::factory()->create();

        $first = $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/suggestions", ['note' => 'Closed.'])
            ->assertCreated()->json('data.id');

        $second = $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/suggestions", ['note' => 'Closed — a barber shop now.'])
            ->assertCreated()->json('data.id');

        expect($second)->toBe($first)
            ->and(PlaceEditSuggestion::query()->count())->toBe(1)
            ->and(PlaceEditSuggestion::query()->sole()->note)->toBe('Closed — a barber shop now.');
    });

    it('shows the note to the operator whose venue it is about', function () {
        $place = Place::factory()->create();
        $owner = operatorOfPlace($place);
        PlaceEditSuggestion::factory()->noteOnly()->create(['place_id' => $place->id]);

        $this->actingAs($owner)
            ->getJson('/api/v1/me/venues/suggestions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            // Without this the operator's card is blank: `changes` is empty and
            // the note is the entire proposal.
            ->assertJsonPath('data.0.note', 'This place closed down last month.')
            ->assertJsonCount(0, 'data.0.changes');
    });
});

describe('actioning a note', function () {
    it('settles a note-only row and records what was done', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
        $moderator = User::factory()->create();
        $suggestion = PlaceEditSuggestion::factory()->noteOnly()->create(['place_id' => $place->id]);

        app(PlaceSuggestionService::class)->action($suggestion, $moderator, 'Confirmed closed by phone; hid the place.');

        $suggestion->refresh();
        expect($suggestion->status)->toBe(SuggestionStatus::Actioned)
            ->and($suggestion->reason)->toBe('Confirmed closed by phone; hid the place.')
            ->and($suggestion->reviewed_by_user_id)->toBe($moderator->id)
            ->and($suggestion->reviewed_at)->not->toBeNull()
            // The verb settles the ROW. Whatever the moderator did to the place
            // they did by hand, through the surfaces that audit it.
            ->and($suggestion->place_edit_id)->toBeNull()
            ->and($place->fresh()->phone)->toBe('+598 2 111 1111')
            ->and(PlaceEdit::query()->where('place_id', $place->id)->count())->toBe(0);
    });

    /**
     * The trap this guard exists for: Actioned must not become the one click
     * that makes an awkward row go away. A row proposing a phone number has a
     * real patch to apply or refuse, and settling it as "dealt with" would leave
     * the correction unwritten under a green-ish badge nobody re-reads.
     */
    it('refuses a row that still proposes a field change', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
        $suggestion = PlaceEditSuggestion::factory()->create([
            'place_id' => $place->id,
            'changes' => ['phone' => ['from' => '+598 2 111 1111', 'to' => '+598 2 900 0000']],
            'note' => 'And the hours are wrong too.',
        ]);

        expect(fn () => app(PlaceSuggestionService::class)->action($suggestion, User::factory()->create(), 'meh'))
            ->toThrow(ValidationException::class);

        expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Pending);
    });

    /**
     * A row with no patch AND no note. `submit()` cannot produce one — it
     * refuses a submission carrying neither — but a seeder, an import or a
     * console command can, and settling it as Actioned would record a decision
     * about nothing: no field written, no finding to point at, and a reviewer's
     * note answering a question nobody asked.
     */
    it('refuses to action a row that says nothing at all', function () {
        $empty = PlaceEditSuggestion::factory()->create(['changes' => [], 'note' => null]);

        expect(fn () => app(PlaceSuggestionService::class)->action($empty, User::factory()->create(), 'done'))
            ->toThrow(ValidationException::class);

        expect($empty->fresh()->status)->toBe(SuggestionStatus::Pending);
    });

    /** The mirror image: approving a note-only row would claim an edit that never happened. */
    it('refuses to approve a row with nothing to apply', function () {
        $suggestion = PlaceEditSuggestion::factory()->noteOnly()->create();

        expect(fn () => app(PlaceSuggestionService::class)->approve($suggestion, User::factory()->create()))
            ->toThrow(ValidationException::class);

        expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Pending);
    });

    it('cannot re-decide a row it already settled', function () {
        $suggestion = PlaceEditSuggestion::factory()->noteOnly()->create();
        $moderator = User::factory()->create();

        // A SEPARATE instance settles it, so `$suggestion` below still holds
        // `pending` in memory — which is the state the guard must not trust.
        // Deciding with `->fresh()` instead would re-read the row first, so the
        // caller's own copy already says `actioned` and the guard is never asked
        // to prefer the locked row over memory: the test would pass against an
        // implementation that only checked `$suggestion->isPending()`.
        app(PlaceSuggestionService::class)->action($suggestion->fresh(), $moderator, 'Hid the place.');

        expect($suggestion->isPending())->toBeTrue();

        // Same `lockPending()` guard as approve/reject — a settled row is
        // settled, whichever verb comes at it next.
        expect(fn () => app(PlaceSuggestionService::class)->action($suggestion, $moderator, 'again'))
            ->toThrow(ValidationException::class);
        expect(fn () => app(PlaceSuggestionService::class)->reject($suggestion, $moderator, 'no'))
            ->toThrow(ValidationException::class);

        expect($suggestion->fresh()->reason)->toBe('Hid the place.');
    });

    it('can still be rejected outright — the abuse path for prose', function () {
        $suggestion = PlaceEditSuggestion::factory()->noteOnly('total nonsense')->create();

        app(PlaceSuggestionService::class)->reject($suggestion, User::factory()->create(), 'Nonsense.');

        expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Rejected);
    });

    it('keeps an actioned row out of the operator\'s pending list', function () {
        $place = Place::factory()->create();
        $owner = operatorOfPlace($place);
        PlaceEditSuggestion::factory()->noteOnly()->actioned()->create(['place_id' => $place->id]);

        $this->actingAs($owner)
            ->getJson('/api/v1/me/venues/suggestions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

describe('the field allow-list', function () {
    it('is a strict subset of the curated fields the editor can write', function () {
        expect(array_diff(PlaceEditSuggestion::FIELDS, Place::CURATED_FIELDS))->toBe([]);
    });

    /**
     * The contract lists these field names AGAIN, by hand, as the `field` enum
     * in place-edit-suggestion.json — and the mobile screen's label map is
     * exhaustive over the TypeScript generated from it. So adding a field here
     * and not there ships an API that emits a value its own schema rejects and
     * its own client cannot name, and nothing else in the build would notice:
     * the contract tests only validate payloads that happen to carry the new
     * field. This is the enforcement.
     */
    it('is mirrored exactly by the contract schema every client generates from', function () {
        // Through the config the contract tests already resolve schemas with —
        // a hardcoded relative path is correct on a laptop and wrong inside the
        // Sail container, where the monorepo is mounted elsewhere.
        $path = config('contracts.schemas_path').'/place-edit-suggestion.json';
        $schema = json_decode((string) file_get_contents($path), true);

        $enum = $schema['properties']['changes']['items']['properties']['field']['enum'] ?? null;

        expect($enum)->toBe(PlaceEditSuggestion::FIELDS);
    });

    it('excludes the picture fields — a stranger may not propose an image URL', function () {
        foreach (['image_url', 'thumbnail_url', 'gallery_json'] as $picture) {
            expect(Place::CURATED_FIELDS)->toContain($picture)
                ->and(PlaceEditSuggestion::FIELDS)->not->toContain($picture);
        }
    });

    it('drops a picture URL from a submitted patch rather than 422-ing on it', function () {
        // Extra keys are ignored by the request (they are not in `rules()`), so
        // the assertion that matters is what LANDS: a suggestion carrying only
        // the allow-listed change, and a place whose hero is untouched.
        // Starts FROM a real hero, not from null: "still null" would pass for a
        // patch that was refused AND for one that silently wrote nothing yet,
        // whereas "still the ORIGINAL" only passes if the field is untouchable.
        $place = Place::factory()->create([
            'image_url' => 'https://cdn.example/real-hero.jpg',
            'phone' => null,
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/suggestions", [
                'phone' => '+598 2 900 0000',
                'image_url' => 'https://evil.example/hero.jpg',
            ])
            ->assertCreated()
            ->assertJsonCount(1, 'data.changes')
            ->assertJsonPath('data.changes.0.field', 'phone');

        expect($place->fresh()->image_url)->toBe('https://cdn.example/real-hero.jpg');
    });

    it('refuses a picture URL even from the operator, whose edit applies on submit', function () {
        $place = Place::factory()->create(['image_url' => 'https://cdn.example/real-hero.jpg']);
        $owner = operatorOfPlace($place);

        $this->actingAs($owner)
            ->postJson("/api/v1/places/{$place->id}/suggestions", [
                'phone' => '+598 2 900 0000',
                'image_url' => 'https://evil.example/hero.jpg',
            ])
            ->assertCreated();

        expect($place->fresh()->image_url)->toBe('https://cdn.example/real-hero.jpg');
    });

    it('validates the country against the bundled ISO list, not a bare length check', function () {
        $place = Place::factory()->create(['country_code' => 'UY']);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/suggestions", ['country_code' => 'ZZ'])
            ->assertStatus(422);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/suggestions", ['country_code' => 'es'])
            ->assertCreated()
            ->assertJsonPath('data.changes.0.to', 'ES');
    });

    it('refuses to empty a column the schema declares NOT NULL', function () {
        $place = Place::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/suggestions", ['name' => ''])
            ->assertStatus(422);
    });
});

describe('moderating', function () {
    it('applies an approved suggestion, locks the fields and links the audit row', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
        $moderator = User::factory()->create(['is_admin' => true]);
        $suggestion = PlaceEditSuggestion::factory()->create([
            'place_id' => $place->id,
            'changes' => ['phone' => ['from' => '+598 2 111 1111', 'to' => '+598 2 900 0000']],
        ]);

        app(PlaceSuggestionService::class)->approve($suggestion, $moderator);

        $place->refresh();
        $suggestion->refresh();

        expect($place->phone)->toBe('+598 2 900 0000')
            ->and($place->isFieldLocked('phone'))->toBeTrue()
            ->and($suggestion->status)->toBe(SuggestionStatus::Approved)
            ->and($suggestion->reviewed_by_user_id)->toBe($moderator->id)
            ->and($suggestion->reviewed_at)->not->toBeNull()
            ->and($suggestion->place_edit_id)->not->toBeNull();

        $edit = PlaceEdit::query()->findOrFail($suggestion->place_edit_id);
        expect($edit->origin)->toBe(PlaceEdit::ORIGIN_MANUAL)
            ->and($edit->user_id)->toBe($moderator->id)
            // Per key, not `toBe` on the whole map: jsonb does not preserve key
            // order (Postgres sorts by length then bytes, so `to` comes back
            // before `from`), and an identity assertion here would fail for a
            // reason that has nothing to do with the audit trail being right.
            ->and($edit->changes['phone']['from'])->toBe('+598 2 111 1111')
            ->and($edit->changes['phone']['to'])->toBe('+598 2 900 0000');
    });

    it('re-diffs at approval time, so a proposal the place already caught up with writes nothing', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
        $suggestion = PlaceEditSuggestion::factory()->create([
            'place_id' => $place->id,
            'changes' => ['phone' => ['from' => '+598 2 111 1111', 'to' => '+598 2 900 0000']],
        ]);

        // Somebody fixed it first — a curator, or the operator.
        $place->update(['phone' => '+598 2 900 0000']);

        app(PlaceSuggestionService::class)->approve($suggestion, User::factory()->create());

        $suggestion->refresh();
        expect($suggestion->status)->toBe(SuggestionStatus::Approved)
            // The honest record of "accepted, changed nothing": no audit row is
            // invented for a write that did not happen.
            ->and($suggestion->place_edit_id)->toBeNull()
            ->and(PlaceEdit::query()->where('place_id', $place->id)->count())->toBe(0);
    });

    it('records a reason on a rejection and leaves the place alone', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
        $suggestion = PlaceEditSuggestion::factory()->create(['place_id' => $place->id]);

        app(PlaceSuggestionService::class)->reject($suggestion, User::factory()->create(), 'No source for this.');

        $suggestion->refresh();
        expect($suggestion->status)->toBe(SuggestionStatus::Rejected)
            ->and($suggestion->reason)->toBe('No source for this.')
            ->and($place->fresh()->phone)->toBe('+598 2 111 1111');
    });

    it('refuses to decide a row somebody already settled', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
        $suggestion = PlaceEditSuggestion::factory()->create([
            'place_id' => $place->id,
            'changes' => ['phone' => ['from' => '+598 2 111 1111', 'to' => '+598 2 900 0000']],
        ]);
        $moderator = User::factory()->create();

        app(PlaceSuggestionService::class)->approve($suggestion, $moderator);
        // A curator corrects it again afterwards — the state a second approval
        // would silently undo.
        $place->update(['phone' => '+598 2 777 7777']);

        expect(fn () => app(PlaceSuggestionService::class)->approve($suggestion->fresh(), $moderator))
            ->toThrow(ValidationException::class);
        expect(fn () => app(PlaceSuggestionService::class)->reject($suggestion->fresh(), $moderator, 'changed my mind'))
            ->toThrow(ValidationException::class);

        expect($place->fresh()->phone)->toBe('+598 2 777 7777');
    });

    /**
     * Two moderators deciding the same row in the same second. The guard reads
     * the LOCKED row, so the second transaction sees the first one's verdict —
     * without the lock both would read `pending` from their own in-memory copy
     * and the row would end up rejected with the place already patched, which is
     * a rejection that changed the place.
     *
     * Simulated deterministically rather than with real threads: the second
     * caller holds a STALE instance, which is exactly the state a concurrent
     * request has when it reaches the guard.
     */
    it('refuses a second decision made from a stale copy of the row', function () {
        $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
        $suggestion = PlaceEditSuggestion::factory()->create([
            'place_id' => $place->id,
            'changes' => ['phone' => ['from' => '+598 2 111 1111', 'to' => '+598 2 900 0000']],
        ]);
        $stale = PlaceEditSuggestion::query()->findOrFail($suggestion->id);

        app(PlaceSuggestionService::class)->approve($suggestion, User::factory()->create());

        expect($stale->isPending())->toBeTrue()
            ->and(fn () => app(PlaceSuggestionService::class)->reject($stale, User::factory()->create(), 'no'))
            ->toThrow(ValidationException::class);

        expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Approved)
            ->and($place->fresh()->phone)->toBe('+598 2 900 0000');
    });

    it('never writes a field outside the allow-list, even from a hand-edited row', function () {
        $place = Place::factory()->create(['image_url' => 'https://cdn.example/real-hero.jpg']);
        $suggestion = PlaceEditSuggestion::factory()->create([
            'place_id' => $place->id,
            // A row that got into the table by some route other than the API —
            // a console command, a bad import, a future surface. The apply path
            // is the last line of defence and it has to hold on its own.
            'changes' => [
                'image_url' => ['from' => null, 'to' => 'https://evil.example/hero.jpg'],
                'phone' => ['from' => null, 'to' => '+598 2 900 0000'],
            ],
        ]);

        app(PlaceSuggestionService::class)->approve($suggestion, User::factory()->create());

        $place->refresh();
        expect($place->image_url)->toBe('https://cdn.example/real-hero.jpg')
            ->and($place->phone)->toBe('+598 2 900 0000');
    });
});

describe('an operator\'s pending list', function () {
    it('shows proposals for the venues they hold a verified claim on', function () {
        $place = Place::factory()->create();
        $owner = operatorOfPlace($place);
        $suggestion = PlaceEditSuggestion::factory()->create(['place_id' => $place->id]);

        $this->actingAs($owner)
            ->getJson('/api/v1/me/venues/suggestions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $suggestion->id)
            ->assertJsonPath('data.0.place.slug', $place->slug);
    });

    it('never names the submitter to the venue', function () {
        $place = Place::factory()->create();
        $owner = operatorOfPlace($place);
        $submitter = User::factory()->create(['username' => 'the_diner']);
        PlaceEditSuggestion::factory()->create(['place_id' => $place->id, 'user_id' => $submitter->id]);

        $res = $this->actingAs($owner)->getJson('/api/v1/me/venues/suggestions')->assertOk();
        $body = $res->getContent();

        // Positive control FIRST: an empty response contains no username either,
        // so "the name is absent" says nothing until the row is present.
        $res->assertJsonCount(1, 'data');

        expect($body)->not->toContain('the_diner')
            ->and($body)->not->toContain($submitter->email);
    });

    it('shows nothing from a venue somebody else runs', function () {
        $owner = operatorOfPlace(Place::factory()->create());
        $otherVenue = Place::factory()->create();
        operatorOfPlace($otherVenue);
        PlaceEditSuggestion::factory()->create(['place_id' => $otherVenue->id]);

        $this->actingAs($owner)
            ->getJson('/api/v1/me/venues/suggestions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('hides settled rows — the list is what still needs a decision', function () {
        $place = Place::factory()->create();
        $owner = operatorOfPlace($place);
        PlaceEditSuggestion::factory()->approved()->create(['place_id' => $place->id]);
        PlaceEditSuggestion::factory()->rejected()->create(['place_id' => $place->id]);

        $this->actingAs($owner)
            ->getJson('/api/v1/me/venues/suggestions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('is empty for a user who runs nothing', function () {
        $place = Place::factory()->create();
        PlaceEditSuggestion::factory()->create(['place_id' => $place->id]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/me/venues/suggestions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

/**
 * The acceptance criterion that is easiest to break without noticing: a place
 * that has been corrected still surfaces EXACTLY as before — a pin on the map
 * and a feed row carrying the reel it was seen in. Editing business fields must
 * not touch ingestion or attribution, and renaming a venue must not move its
 * URL.
 */
describe('sharing is preserved', function () {
    it('keeps the pin, the feed row and its source post after an operator renames the venue', function () {
        $place = Place::factory()->active()->atPoint(-34.9011, -56.1645)->create(['name' => 'Cantina Vieja']);
        $owner = operatorOfPlace($place);
        $share = publishedShare($place->fresh());
        $slugBefore = $place->slug;

        $this->actingAs($owner)
            ->postJson("/api/v1/places/{$place->id}/suggestions", ['name' => 'Cantina Nueva'])
            ->assertCreated();

        $place->refresh();

        // The URL is IDENTITY, not a label. A rename that re-slugged would break
        // every link already shared and every client cache entry keyed on it.
        expect($place->slug)->toBe($slugBefore)
            ->and($place->name)->toBe('Cantina Nueva')
            // The dedup key does follow the name — it is a matching column, not
            // an address — so a re-share of the renamed venue still resolves here.
            ->and($place->normalized_name)->toBe('cantina nueva');

        // Still a pin, under the new name.
        $pins = $this->getJson('/api/v1/map/places?bbox='.MONTEVIDEO_BBOX.'&zoom=16')
            ->assertOk()->json('data.pins');
        expect(collect($pins)->pluck('name'))->toContain('Cantina Nueva');

        // Still in the feed, still attributed to the reel it came from.
        $item = collect($this->getJson('/api/v1/feed')->assertOk()->json('data'))
            ->firstWhere('id', (string) $share->id);

        expect($item)->not->toBeNull()
            ->and($item['place']['slug'])->toBe($slugBefore)
            ->and($item['place']['name'])->toBe('Cantina Nueva')
            ->and($item['source_post']['url'])->not->toBeNull();

        // And the attribution list itself is untouched — one source, the reel.
        $this->getJson("/api/v1/places/{$place->slug}/sources")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('leaves the place, its pin and its sources alone while a suggestion sits in the queue', function () {
        $place = Place::factory()->active()->atPoint(-34.9011, -56.1645)->create(['name' => 'Cantina Vieja']);
        publishedShare($place);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/suggestions", ['name' => 'Cantina Nueva'])
            ->assertCreated();

        expect($place->fresh()->name)->toBe('Cantina Vieja');

        $pins = $this->getJson('/api/v1/map/places?bbox='.MONTEVIDEO_BBOX.'&zoom=16')
            ->assertOk()->json('data.pins');
        expect(collect($pins)->pluck('name'))->toContain('Cantina Vieja');
    });
});

describe('the place payload', function () {
    it('tells a verified operator they may edit directly', function () {
        $place = Place::factory()->create();
        $owner = operatorOfPlace($place);

        $this->actingAs($owner)
            ->getJson("/api/v1/places/{$place->slug}")
            ->assertOk()
            ->assertJsonPath('data.can_edit', true);
    });

    it('tells everyone else they may not — including guests', function () {
        $place = Place::factory()->create();
        operatorOfPlace($place);

        $this->getJson("/api/v1/places/{$place->slug}")
            ->assertOk()
            ->assertJsonPath('data.can_edit', false);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/places/{$place->slug}")
            ->assertOk()
            ->assertJsonPath('data.can_edit', false);
    });

    it('carries the street line the suggest form corrects', function () {
        $place = Place::factory()->create([
            'address_line1' => 'Bartolomé Mitre 1327',
            'city' => 'Montevideo',
            // Pinned: the factory picks a random country, and `address` is the
            // joined string this asserts on.
            'country_code' => 'UY',
            'region' => null,
        ]);

        $this->getJson("/api/v1/places/{$place->slug}")
            ->assertOk()
            // Beside the joined display string, not instead of it — the detail
            // screen renders `address` and the form edits `address_line1`.
            ->assertJsonPath('data.address_line1', 'Bartolomé Mitre 1327')
            ->assertJsonPath('data.address', 'Bartolomé Mitre 1327, Montevideo, UY');
    });
});
