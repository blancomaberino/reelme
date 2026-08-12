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
