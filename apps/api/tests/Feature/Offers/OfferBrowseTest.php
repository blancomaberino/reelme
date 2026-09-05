<?php

use App\Enums\PlaceStatus;
use App\Models\Offer;
use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Diner-facing offer reads (T-042, 03 §2.12) — the flat browse, the detail, the
 * `?include=offers` embed, and the map badge.
 *
 * The organising property: **`status = active` is never sufficient to show an
 * offer.** Nothing rewrites the column when a window lapses, so every read path
 * has to evaluate the window itself. Each surface gets the same lapsed-offer
 * fixture, because a surface that forgets is a surface that advertises a
 * promotion the restaurant stopped honouring.
 */

/** One offer in each state a diner-facing surface has to reason about. */
function placeWithOfferMix(?Place $place = null): Place
{
    $place = $place ?? Place::factory()->active()->atPoint(38.7223, -9.1393)->create();
    $operator = User::factory()->create();
    PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $operator->id]);

    Offer::factory()->active()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id, 'title' => 'Live one']);
    Offer::factory()->expired()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id, 'title' => 'Window lapsed']);
    Offer::factory()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id, 'title' => 'Draft']);
    Offer::factory()->paused()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id, 'title' => 'Paused']);
    Offer::factory()->archived()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id, 'title' => 'Archived']);

    return $place;
}

describe('GET /offers', function () {
    it('is public and hides drafts, paused, and archived offers', function () {
        $place = placeWithOfferMix();

        $titles = collect($this->getJson('/api/v1/offers')->assertOk()->json('data'))->pluck('title');

        expect($titles)->toContain('Live one', 'Window lapsed')
            ->and($titles)->not->toContain('Draft')
            ->and($titles)->not->toContain('Paused')
            ->and($titles)->not->toContain('Archived');
    });

    it('drops an offer whose window has lapsed when ?active=1', function () {
        placeWithOfferMix();

        $titles = collect($this->getJson('/api/v1/offers?active=1')->assertOk()->json('data'))->pluck('title');

        expect($titles)->toContain('Live one')
            // Still `status = active` in the column — only the window says no.
            ->and($titles)->not->toContain('Window lapsed');
    });

    it('does not include an offer whose window has not opened yet under ?active=1', function () {
        $place = Place::factory()->active()->create();
        Offer::factory()->upcoming()->create(['place_id' => $place->id, 'title' => 'Next week']);

        $all = collect($this->getJson('/api/v1/offers')->assertOk()->json('data'))->pluck('title');
        $live = collect($this->getJson('/api/v1/offers?active=1')->assertOk()->json('data'))->pluck('title');

        // Advertisable ahead of time, but not redeemable yet.
        expect($all)->toContain('Next week')
            ->and($live)->not->toContain('Next week');
    });

    it('filters by place_id', function () {
        $wanted = placeWithOfferMix();
        placeWithOfferMix(Place::factory()->active()->atPoint(41.15, -8.61)->create());

        $rows = $this->getJson("/api/v1/offers?place_id={$wanted->id}")->assertOk()->json('data');

        expect($rows)->not->toBeEmpty();
        foreach ($rows as $row) {
            expect($row['place_id'])->toBe((string) $wanted->id);
        }
    });

    it('filters by distance from a point', function () {
        $near = Place::factory()->active()->atPoint(38.7223, -9.1393)->create();
        $far = Place::factory()->active()->atPoint(41.1579, -8.6291)->create();
        Offer::factory()->active()->create(['place_id' => $near->id, 'title' => 'Lisbon']);
        Offer::factory()->active()->create(['place_id' => $far->id, 'title' => 'Porto']);

        $titles = collect(
            $this->getJson('/api/v1/offers?near=38.7223,-9.1393&radius_m=5000')->assertOk()->json('data')
        )->pluck('title');

        expect($titles)->toContain('Lisbon')->and($titles)->not->toContain('Porto');
    });

    it('rejects a malformed near parameter', function () {
        $this->getJson('/api/v1/offers?near=38.7223')->assertStatus(422);
    });

    it('ignores caller-supplied nearLat/nearLng — the THIRD surface, same rule', function () {
        // `/offers` was the copy nobody remembered. T-156 hardened `/map/places`,
        // then `/places` when review found the second one — and this stayed on
        // the old conditional merge, so the identical input got a 422 from two
        // endpoints and a confident 200 from this one, geofencing from a point
        // `near` never named. All three parse through ParsesNearPoint now.
        $near = Place::factory()->active()->atPoint(38.7223, -9.1393)->create();
        Offer::factory()->active()->create(['place_id' => $near->id, 'title' => 'Lisbon']);

        $this->getJson('/api/v1/offers?near=1,2,3&nearLat=38.7223&nearLng=-9.1393&radius_m=5000')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    });

    it('geofences from a position it has ROUNDED, like every other surface', function () {
        // The privacy policy's "rounded to about ten metres" is a claim about the
        // SYSTEM. It was true of two endpoints out of three: this one took nine
        // decimals straight into the access log.
        //
        // Placed so the rounding CHANGES the answer, which a gentler offset does
        // not: 38.72235 rounds up to 38.7224, putting the caller 0.0001°
        // (~11.1 m) from the place, while the raw value is ~5.6 m away. At an
        // 8 m radius those are opposite results, with ~2.5 m of margin on each
        // side. Two earlier versions were worse: one used a sub-metre offset
        // inside the radius and passed with the rounding deleted; the next left
        // 9 mm of margin and a comment naming a radius (10.5) that `radius_m`,
        // being an integer rule, cannot even express.
        $place = Place::factory()->active()->atPoint(38.72230, -9.13930)->create();
        Offer::factory()->active()->create(['place_id' => $place->id, 'title' => 'Lisbon']);

        $rounded = $this->getJson('/api/v1/offers?near=38.72235,-9.13930&radius_m=8')
            ->assertOk()->json('data');
        // The control: at 4 dp exactly, the same request finds it. Without this,
        // deleting the offer entirely would satisfy the assertion above.
        $inside = $this->getJson('/api/v1/offers?near=38.72230,-9.13930&radius_m=8')
            ->assertOk()->json('data');

        expect($rounded)->toHaveCount(0)
            ->and($inside)->toHaveCount(1);
    });

    it('never surfaces an offer on a hidden or merged place', function () {
        $survivor = Place::factory()->active()->create();
        $tombstone = Place::factory()->create([
            'status' => PlaceStatus::Merged,
            'merged_into_place_id' => $survivor->id,
        ]);
        Offer::factory()->active()->create(['place_id' => $tombstone->id, 'title' => 'On a tombstone']);

        $titles = collect($this->getJson('/api/v1/offers')->assertOk()->json('data'))->pluck('title');

        expect($titles)->not->toContain('On a tombstone');
    });

    it('cursor-paginates with the standard envelope', function () {
        $place = Place::factory()->active()->create();
        Offer::factory()->active()->count(3)->create(['place_id' => $place->id]);

        $first = $this->getJson('/api/v1/offers?limit=2')->assertOk();
        expect($first->json('data'))->toHaveCount(2)
            ->and($first->json('meta.pagination.limit'))->toBe(2)
            ->and($first->json('meta.pagination.next_cursor'))->not->toBeNull();

        $cursor = $first->json('meta.pagination.next_cursor');
        $second = $this->getJson('/api/v1/offers?limit=2&cursor='.urlencode($cursor))->assertOk();

        expect($second->json('data'))->toHaveCount(1)
            ->and($second->json('meta.pagination.next_cursor'))->toBeNull()
            // No overlap between pages — the keyset actually advanced.
            ->and(collect($second->json('data'))->pluck('id')->intersect(collect($first->json('data'))->pluck('id')))
            ->toBeEmpty();
    });
});

describe('the operator view (?mine=1)', function () {
    it('shows the operator their drafts and paused offers', function () {
        $place = Place::factory()->active()->create();
        $operator = User::factory()->create();
        PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $operator->id]);
        Offer::factory()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id, 'title' => 'Draft']);
        Offer::factory()->paused()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id, 'title' => 'Paused']);
        Offer::factory()->active()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id, 'title' => 'Live']);

        Sanctum::actingAs($operator);
        $titles = collect(
            $this->getJson('/api/v1/offers?mine=1')->assertOk()->json('data')
        )->pluck('title');

        expect($titles)->toContain('Draft', 'Paused', 'Live');
    });

    it('never shows another operator their venue', function () {
        $mine = Place::factory()->active()->create();
        $theirs = Place::factory()->active()->create();
        $operator = User::factory()->create();
        PlaceClaim::factory()->verified()->create(['place_id' => $mine->id, 'user_id' => $operator->id]);
        Offer::factory()->create(['place_id' => $mine->id, 'title' => 'Mine']);
        Offer::factory()->create(['place_id' => $theirs->id, 'title' => 'Not mine']);

        Sanctum::actingAs($operator);
        $titles = collect(
            $this->getJson('/api/v1/offers?mine=1')->assertOk()->json('data')
        )->pluck('title');

        expect($titles)->toContain('Mine')->and($titles)->not->toContain('Not mine');
    });

    /*
     * `?active=1` used to be ignored in the operator branch, so an operator
     * asking "what can a diner redeem right now" got their drafts back too —
     * the one question the flag exists to answer.
     */
    it('honours ?active=1 for the operator too', function () {
        $place = Place::factory()->active()->create();
        $operator = User::factory()->create();
        PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $operator->id]);
        Offer::factory()->create(['place_id' => $place->id, 'title' => 'Draft']);
        Offer::factory()->expired()->create(['place_id' => $place->id, 'title' => 'Window lapsed']);
        Offer::factory()->active()->create(['place_id' => $place->id, 'title' => 'Live']);

        Sanctum::actingAs($operator);
        $titles = collect($this->getJson('/api/v1/offers?mine=1&active=1')->assertOk()->json('data'))->pluck('title');

        expect($titles)->toContain('Live')
            ->and($titles)->not->toContain('Draft')
            ->and($titles)->not->toContain('Window lapsed');
    });

    it('401s for an anonymous caller', function () {
        $this->getJson('/api/v1/offers?mine=1')->assertUnauthorized();
    });

    it('returns nothing for an authenticated user who operates no venue', function () {
        placeWithOfferMix();

        Sanctum::actingAs(User::factory()->create());
        $rows = $this->getJson('/api/v1/offers?mine=1')->assertOk()->json('data');

        expect($rows)->toBeEmpty();
    });
});

describe('GET /offers/{id}', function () {
    it('is public for a published offer and carries the venue', function () {
        $place = Place::factory()->active()->create(['name' => 'Taberna']);
        $offer = Offer::factory()->active()->create(['place_id' => $place->id]);

        $this->getJson("/api/v1/offers/{$offer->id}")
            ->assertOk()
            ->assertJsonPath('data.id', (string) $offer->id)
            ->assertJsonPath('data.place.name', 'Taberna')
            ->assertJsonPath('data.is_redeemable', true);
    });

    /*
     * 404, not 403: a draft is the operator's private working state, and a
     * status that changed between the two would tell a stranger the offer
     * exists.
     */
    it('404s a draft for everyone but the operator', function () {
        $place = Place::factory()->active()->create();
        $operator = User::factory()->create();
        PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $operator->id]);
        $offer = Offer::factory()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id]);

        $this->getJson("/api/v1/offers/{$offer->id}")->assertNotFound();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/offers/{$offer->id}")->assertNotFound();

        Sanctum::actingAs($operator);
        $this->getJson("/api/v1/offers/{$offer->id}")->assertOk();
    });
});

describe('the place-detail embed', function () {
    it('embeds only live offers under ?include=offers', function () {
        $place = placeWithOfferMix();

        $res = $this->getJson("/api/v1/places/{$place->slug}?include=offers")->assertOk();

        $titles = collect($res->json('data.offers'))->pluck('title');
        expect($titles)->toContain('Live one')
            ->and($titles)->not->toContain('Window lapsed')
            ->and($titles)->not->toContain('Draft')
            ->and($titles)->not->toContain('Paused')
            ->and($titles)->not->toContain('Archived');
    });

    it('omits the offers key entirely without the include', function () {
        $place = placeWithOfferMix();

        $this->getJson("/api/v1/places/{$place->slug}")
            ->assertOk()
            ->assertJsonMissingPath('data.offers');
    });
});

describe('the map badge', function () {
    it('flags a pin whose place has a live offer, and only that pin', function () {
        $withOffer = Place::factory()->active()->atPoint(38.7223, -9.1393)->create();
        $withLapsed = Place::factory()->active()->atPoint(38.7225, -9.1395)->create();
        $without = Place::factory()->active()->atPoint(38.7227, -9.1397)->create();
        Offer::factory()->active()->create(['place_id' => $withOffer->id]);
        Offer::factory()->expired()->create(['place_id' => $withLapsed->id]);

        $pins = collect(
            $this->getJson('/api/v1/map/places?bbox=-9.2,38.70,-9.10,38.75&zoom=16')->assertOk()->json('data.pins')
        )->keyBy('id');

        expect($pins[(string) $withOffer->id]['has_active_offer'])->toBeTrue()
            ->and($pins[(string) $withLapsed->id]['has_active_offer'])->toBeFalse()
            ->and($pins[(string) $without->id]['has_active_offer'])->toBeFalse();
    });
});
