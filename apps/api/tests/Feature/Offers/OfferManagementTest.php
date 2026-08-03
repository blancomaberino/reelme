<?php

use App\Enums\OfferDiscountType;
use App\Enums\OfferStatus;
use App\Http\Requests\Offers\OfferWriteRequest;
use App\Models\Offer;
use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Owner-scoped offer management (T-042, 03 §2.12, 06 §2.2).
 *
 * The organising property: **the right to run offers is re-derived from the
 * verified place claim on every request.** It is never read off the offer's
 * `created_by_user_id`, never cached on the user, and never granted by having
 * created the row — because an operator whose claim is revoked (a disputed
 * venue, a restaurant sold) must lose control of the offers they created,
 * including the fees those offers draw.
 */

/**
 * The API answers validation failures in the canonical envelope
 * (`{"error": {"details": {field: [...]}}}`, 03 §1), not Laravel's default
 * top-level `errors` key — so `assertInvalid()` does not apply here.
 *
 * @param  list<string>  $fields
 */
function assertRejectedFields(TestResponse $response, array $fields): void
{
    $details = $response->assertStatus(422)->json('error.details');

    expect(array_keys($details))->toContain(...$fields);
}

/** A user holding the place's one verified claim — a real operator. */
function operatorOf(Place $place): User
{
    $user = User::factory()->create();
    PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $user->id]);

    return $user;
}

/** A well-formed create body, overridable field by field. */
function offerPayload(Place $place, array $overrides = []): array
{
    return array_merge([
        'place_id' => $place->id,
        'title' => 'Two-for-one pastéis',
        'description' => 'Weekday afternoons only.',
        'discount_type' => 'percent',
        'discount_value' => 20,
        'terms' => 'One per table. Not valid with other promotions.',
        'starts_at' => now()->toIso8601String(),
        'ends_at' => now()->addDays(30)->toIso8601String(),
        'status' => 'active',
    ], $overrides);
}

describe('creating an offer', function () {
    it('lets a verified operator create one for their place', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOf($place);

        $res = $this->actingAs($operator)
            ->postJson('/api/v1/offers', offerPayload($place, ['quota_total' => 100, 'quota_per_day' => 10]))
            ->assertCreated();

        expect($res->json('data.title'))->toBe('Two-for-one pastéis')
            ->and($res->json('data.discount_type'))->toBe('percent')
            ->and($res->json('data.discount_value'))->toBe(20)
            ->and($res->json('data.status'))->toBe('active')
            ->and($res->json('data.is_redeemable'))->toBeTrue()
            ->and($res->json('data.remaining_quota'))->toBe(100)
            ->and($res->json('data.place.id'))->toBe((string) $place->id);

        $offer = Offer::firstOrFail();
        expect($offer->place_id)->toBe($place->id)
            ->and($offer->created_by_user_id)->toBe($operator->id)
            ->and($offer->quota_per_day)->toBe(10)
            // Never mass-assignable: a body that could set it could reset a quota.
            ->and($offer->redemptions_count)->toBe(0);
    });

    it('rejects a user with no claim on the place', function () {
        $place = Place::factory()->active()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson('/api/v1/offers', offerPayload($place))
            ->assertForbidden();

        expect(Offer::count())->toBe(0);
    });

    it('rejects a user whose claim is only pending', function () {
        $place = Place::factory()->active()->create();
        $hopeful = User::factory()->create();
        PlaceClaim::factory()->create(['place_id' => $place->id, 'user_id' => $hopeful->id]);

        $this->actingAs($hopeful)
            ->postJson('/api/v1/offers', offerPayload($place))
            ->assertForbidden();
    });

    it('rejects an operator of a DIFFERENT place', function () {
        $mine = Place::factory()->active()->create();
        $theirs = Place::factory()->active()->create();
        $operator = operatorOf($mine);

        $this->actingAs($operator)
            ->postJson('/api/v1/offers', offerPayload($theirs))
            ->assertForbidden();
    });

    it('requires authentication', function () {
        $place = Place::factory()->active()->create();

        $this->postJson('/api/v1/offers', offerPayload($place))->assertUnauthorized();
    });

    /*
     * A nonexistent place answers 403, not 404 — the same status as "not your
     * place", so the endpoint cannot be used to enumerate which venue ids exist.
     */
    it('answers 403 for an unknown place, indistinguishable from an unowned one', function () {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/offers', ['place_id' => 999999] + offerPayload(Place::factory()->active()->create()))
            ->assertForbidden();
    });

    /*
     * 02 §3.13 makes `draft` the column default. The model mirrors it in
     * `$attributes` so a freshly created row answers the same as one read back
     * — without that, the CREATE response carries a null status for a row
     * Postgres stored as `draft`, and `OfferResource` fatals reading ->value.
     */
    it('creates a draft when no status is asserted', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOf($place);
        $payload = offerPayload($place);
        unset($payload['status']);

        $res = $this->actingAs($operator)->postJson('/api/v1/offers', $payload)->assertCreated();

        expect($res->json('data.status'))->toBe('draft')
            ->and($res->json('data.is_redeemable'))->toBeFalse()
            ->and($res->json('data.quota_per_user'))->toBe(1)
            ->and(Offer::firstOrFail()->status)->toBe(OfferStatus::Draft);
    });

    it('defaults an omitted end date to the 90-day maximum rather than open-ended', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOf($place);
        $startsAt = now()->startOfHour();

        $this->actingAs($operator)
            ->postJson('/api/v1/offers', offerPayload($place, [
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => null,
            ]))
            ->assertCreated();

        $offer = Offer::firstOrFail();
        expect($offer->ends_at)->not->toBeNull()
            ->and($offer->ends_at->toIso8601String())
            ->toBe($startsAt->copy()->addDays(OfferWriteRequest::MAX_WINDOW_DAYS)->toIso8601String());
    });
});

describe('create validation', function () {
    it('rejects a percentage outside the 5–50 band', function (int $value) {
        $place = Place::factory()->active()->create();

        $this->actingAs(operatorOf($place))
            ->postJson('/api/v1/offers', offerPayload($place, ['discount_value' => $value]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.discount_value.0', fn (string $m) => str_contains($m, '5%'));
    })->with([1, 4, 51, 100]);

    it('accepts the band edges', function (int $value) {
        $place = Place::factory()->active()->create();

        $this->actingAs(operatorOf($place))
            ->postJson('/api/v1/offers', offerPayload($place, ['discount_value' => $value]))
            ->assertCreated();
    })->with([5, 50]);

    it('rejects a validity window longer than 90 days', function () {
        $place = Place::factory()->active()->create();

        $this->actingAs(operatorOf($place))
            ->postJson('/api/v1/offers', offerPayload($place, [
                'starts_at' => now()->toIso8601String(),
                'ends_at' => now()->addDays(91)->toIso8601String(),
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.ends_at.0', fn (string $m) => str_contains($m, '90 days'));
    });

    it('rejects a window that ends before it starts', function () {
        $place = Place::factory()->active()->create();

        $res = $this->actingAs(operatorOf($place))
            ->postJson('/api/v1/offers', offerPayload($place, [
                'starts_at' => now()->addDays(10)->toIso8601String(),
                'ends_at' => now()->addDays(2)->toIso8601String(),
            ]))
            ->assertStatus(422);

        assertRejectedFields($res, ['ends_at']);
    });

    it('requires starts_at, title, and a discount', function () {
        $place = Place::factory()->active()->create();

        $res = $this->actingAs(operatorOf($place))
            ->postJson('/api/v1/offers', ['place_id' => $place->id])
            ->assertStatus(422);

        assertRejectedFields($res, ['title', 'discount_type', 'discount_value', 'starts_at']);
    });

    it('rejects a fixed discount large enough to be a slipped decimal', function () {
        $place = Place::factory()->active()->create();

        $res = $this->actingAs(operatorOf($place))
            ->postJson('/api/v1/offers', offerPayload($place, [
                'discount_type' => 'fixed_amount',
                'discount_value' => 5_000_000,
            ]))
            ->assertStatus(422);

        assertRejectedFields($res, ['discount_value']);
    });

    it('accepts a fixed discount in minor units', function () {
        $place = Place::factory()->active()->create();

        $this->actingAs(operatorOf($place))
            ->postJson('/api/v1/offers', offerPayload($place, [
                'discount_type' => 'fixed_amount',
                'discount_value' => 350,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.discount_value', 350);
    });

    it('refuses to let an operator assert a computed or terminal status', function (string $status) {
        $place = Place::factory()->active()->create();

        $res = $this->actingAs(operatorOf($place))
            ->postJson('/api/v1/offers', offerPayload($place, ['status' => $status]))
            ->assertStatus(422);

        assertRejectedFields($res, ['status']);
    })->with(['expired', 'archived']);

    it('rejects a free-item count large enough to be a typo', function () {
        $place = Place::factory()->active()->create();

        $res = $this->actingAs(operatorOf($place))
            ->postJson('/api/v1/offers', offerPayload($place, [
                'discount_type' => 'free_item',
                'discount_value' => 500,
            ]))
            ->assertStatus(422);

        assertRejectedFields($res, ['discount_value']);
    });

    /*
     * A body whose place_id is not even a number must not reach the policy —
     * `Place::find('abc')` would be a query with a nonsense binding, and the
     * caller learns nothing either way.
     */
    it('rejects a non-numeric place_id without consulting the policy', function () {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/offers', ['place_id' => 'not-an-id', 'title' => 'x'])
            ->assertForbidden();
    });

    it('rejects a zero or negative quota', function () {
        $place = Place::factory()->active()->create();

        $res = $this->actingAs(operatorOf($place))
            ->postJson('/api/v1/offers', offerPayload($place, ['quota_total' => 0, 'quota_per_day' => -1]))
            ->assertStatus(422);

        assertRejectedFields($res, ['quota_total', 'quota_per_day']);
    });
});

describe('updating an offer', function () {
    it('lets the operator pause a live offer', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOf($place);
        $offer = Offer::factory()->active()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id]);

        $this->actingAs($operator)
            ->patchJson("/api/v1/offers/{$offer->id}", ['status' => 'paused'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.is_redeemable', false);

        expect($offer->fresh()->status)->toBe(OfferStatus::Paused);
    });

    it('lets the operator edit the terms and the discount', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOf($place);
        $offer = Offer::factory()->active()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id]);

        $this->actingAs($operator)
            ->patchJson("/api/v1/offers/{$offer->id}", [
                'title' => 'Renamed',
                'discount_type' => 'free_item',
                'discount_value' => 2,
            ])
            ->assertOk();

        $offer->refresh();
        expect($offer->title)->toBe('Renamed')
            ->and($offer->discount_type)->toBe(OfferDiscountType::FreeItem)
            ->and($offer->discount_value)->toBe(2);
    });

    /*
     * The two-request escape from the 90-day cap: send a far-future `ends_at`
     * on its own and let it be checked against nothing. Every cross-field rule
     * reads the MERGED row for exactly this reason.
     */
    it('checks a partial window edit against the stored start, not against nothing', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOf($place);
        $offer = Offer::factory()->active()->create([
            'place_id' => $place->id,
            'created_by_user_id' => $operator->id,
            'starts_at' => now(),
            'ends_at' => now()->addDays(10),
        ]);

        $res = $this->actingAs($operator)
            ->patchJson("/api/v1/offers/{$offer->id}", ['ends_at' => now()->addDays(200)->toIso8601String()])
            ->assertStatus(422);

        assertRejectedFields($res, ['ends_at']);
    });

    it('leaves an untouched window alone on an unrelated edit', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOf($place);
        $offer = Offer::factory()->active()->create([
            'place_id' => $place->id,
            'created_by_user_id' => $operator->id,
            'ends_at' => null,
        ]);

        $this->actingAs($operator)
            ->patchJson("/api/v1/offers/{$offer->id}", ['title' => 'Just a rename'])
            ->assertOk();

        expect($offer->fresh()->ends_at)->toBeNull();
    });

    /*
     * The mobile form deliberately sends no status on an edit, so the API must
     * leave the stored one alone. If a PATCH without `status` ever started
     * defaulting one, a paused offer would go back in front of diners on the
     * next typo fix — silently, and only in production.
     */
    it('leaves a paused offer paused when the edit carries no status', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOf($place);
        $offer = Offer::factory()->paused()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id]);

        $this->actingAs($operator)
            ->patchJson("/api/v1/offers/{$offer->id}", ['title' => 'Fixed a typo'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.is_redeemable', false);

        expect($offer->fresh()->status)->toBe(OfferStatus::Paused);
    });

    it('rejects an edit from someone who does not operate the place', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOf($place);
        $offer = Offer::factory()->active()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id]);

        $this->actingAs(User::factory()->create())
            ->patchJson("/api/v1/offers/{$offer->id}", ['status' => 'paused'])
            ->assertForbidden();

        expect($offer->fresh()->status)->toBe(OfferStatus::Active);
    });

    /*
     * The oracle this closes: the cross-field rules read the STORED offer, so if
     * authorization ran after validation a stranger could PATCH a lone `ends_at`
     * and binary-search the stored `starts_at` out of the error messages — of a
     * draft they cannot even GET. A 422 here instead of a 403 is the bug.
     */
    it('answers 403, not a validation error, when a non-operator sends an invalid body', function () {
        $place = Place::factory()->active()->create();
        $offer = Offer::factory()->create([
            'place_id' => $place->id,
            'created_by_user_id' => operatorOf($place)->id,
            'starts_at' => now(),
            'ends_at' => now()->addDays(10),
        ]);

        $res = $this->actingAs(User::factory()->create())
            ->patchJson("/api/v1/offers/{$offer->id}", ['ends_at' => now()->addDays(500)->toIso8601String()])
            ->assertForbidden();

        // Nothing about the stored window comes back.
        expect($res->json('error.details'))->toBe([])
            ->and($res->content())->not->toContain('90 days');
    });

    /*
     * Creating the offer is not the permission — holding the claim is. A
     * revoked claim has to take the offers with it, or a former operator keeps
     * drawing fees against a venue that is no longer theirs.
     */
    it('rejects the original creator once their claim is revoked', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOf($place);
        $offer = Offer::factory()->active()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id]);

        PlaceClaim::query()->where('user_id', $operator->id)->delete();

        $this->actingAs($operator)
            ->patchJson("/api/v1/offers/{$offer->id}", ['status' => 'paused'])
            ->assertForbidden();
    });

    /*
     * The discount rules run on the MERGED row, so switching the unit alone has
     * to be checked against the stored value — a percent offer at 350 flipped to
     * fixed_amount is fine, but a fixed offer at 350 flipped to percent is 350%.
     */
    it('rechecks the stored value against a newly chosen discount unit', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOf($place);
        $offer = Offer::factory()->active()->fixedAmount(350)->create([
            'place_id' => $place->id,
            'created_by_user_id' => $operator->id,
        ]);

        $res = $this->actingAs($operator)
            ->patchJson("/api/v1/offers/{$offer->id}", ['discount_type' => 'percent'])
            ->assertStatus(422);

        assertRejectedFields($res, ['discount_value']);
    });

    it('sets an omitted end date to the 90-day maximum when the start is moved', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOf($place);
        $offer = Offer::factory()->active()->create([
            'place_id' => $place->id,
            'created_by_user_id' => $operator->id,
            'ends_at' => null,
        ]);
        $newStart = now()->addDay()->startOfHour();

        $this->actingAs($operator)
            ->patchJson("/api/v1/offers/{$offer->id}", ['starts_at' => $newStart->toIso8601String()])
            ->assertOk();

        expect($offer->fresh()->ends_at?->toIso8601String())
            ->toBe($newStart->copy()->addDays(OfferWriteRequest::MAX_WINDOW_DAYS)->toIso8601String());
    });

    it('refuses to edit an archived offer', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOf($place);
        $offer = Offer::factory()->archived()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id]);

        $this->actingAs($operator)
            ->patchJson("/api/v1/offers/{$offer->id}", ['title' => 'Resurrected'])
            ->assertStatus(409);

        expect($offer->fresh()->title)->not->toBe('Resurrected');
    });
});

describe('deleting an offer', function () {
    it('archives rather than hard-deletes', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOf($place);
        $offer = Offer::factory()->active()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id]);

        $this->actingAs($operator)
            ->deleteJson("/api/v1/offers/{$offer->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        // The row survives: redemptions and ledger entries point at it.
        expect(Offer::query()->find($offer->id))->not->toBeNull()
            ->and($offer->fresh()->status)->toBe(OfferStatus::Archived);
    });

    it('is idempotent', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOf($place);
        $offer = Offer::factory()->archived()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id]);

        $this->actingAs($operator)
            ->deleteJson("/api/v1/offers/{$offer->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
    });

    it('rejects a non-operator', function () {
        $place = Place::factory()->active()->create();
        $offer = Offer::factory()->active()->create(['place_id' => $place->id, 'created_by_user_id' => operatorOf($place)->id]);

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/v1/offers/{$offer->id}")
            ->assertForbidden();

        expect($offer->fresh()->status)->toBe(OfferStatus::Active);
    });
});
