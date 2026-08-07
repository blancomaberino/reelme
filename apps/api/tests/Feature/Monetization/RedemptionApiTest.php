<?php

use App\Enums\RedemptionStatus;
use App\Models\Offer;
use App\Models\Place;
use App\Models\Redemption;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * The redemption endpoints (T-043, 03 §2.13).
 *
 * The organising property here is **who may see the CODE.** It is a bearer
 * token: whoever holds it eats for free. The diner must have it (they present
 * it), the operator must never be handed one they have not been shown (their
 * log would double as a list of free meals), and nobody else may read the row
 * at all — it also carries the attribution chain, i.e. someone's earnings.
 */
describe('POST /redemptions', function () {
    it('issues a code to the caller and returns it', function () {
        $place = Place::factory()->active()->create();
        $offer = Offer::factory()->active()->create(['place_id' => $place->id]);
        $diner = User::factory()->create();

        $res = $this->actingAs($diner)
            ->postJson('/api/v1/redemptions', ['offer_id' => $offer->id])
            ->assertCreated();

        expect($res->json('data.status'))->toBe('issued')
            ->and($res->json('data.is_live'))->toBeTrue()
            ->and($res->json('data.code'))->toHaveLength(10)
            // The grouped form the client shows, so it never re-implements it.
            ->and($res->json('data.code_display'))->toContain('-')
            ->and($res->json('data.qr_payload'))->toStartWith('v1.');

        expect(Redemption::firstOrFail()->user_id)->toBe($diner->id);
    });

    it('requires authentication', function () {
        $offer = Offer::factory()->active()->create(['place_id' => Place::factory()->active()]);

        $this->postJson('/api/v1/redemptions', ['offer_id' => $offer->id])->assertUnauthorized();
    });

    it('answers with a reason a client can branch on', function () {
        $place = Place::factory()->active()->create();
        $offer = Offer::factory()->paused()->create(['place_id' => $place->id]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/redemptions', ['offer_id' => $offer->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'redemption_invalid')
            ->assertJsonPath('error.details.reason', 'offer_not_redeemable');
    });

    it('treats an unknown offer the same as an unavailable one', function () {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/redemptions', ['offer_id' => 999999])
            ->assertStatus(422);
    });
});

describe('GET /redemptions/{id}', function () {
    it('shows the diner their own code', function () {
        $place = Place::factory()->active()->create();
        $diner = User::factory()->create();
        $redemption = Redemption::factory()->create([
            'offer_id' => Offer::factory()->active()->create(['place_id' => $place->id])->id,
            'user_id' => $diner->id,
        ]);

        $this->actingAs($diner)
            ->getJson("/api/v1/redemptions/{$redemption->id}")
            ->assertOk()
            ->assertJsonPath('data.code', $redemption->code);
    });

    /*
     * The operator may READ the row — they need to see what was redeemed at
     * their venue — but never the code. A code they have not been presented
     * with is a free meal they can hand to anyone.
     */
    it('shows the venue operator the row but withholds the code', function () {
        $place = Place::factory()->active()->create();
        $redemption = Redemption::factory()->create([
            'offer_id' => Offer::factory()->active()->create(['place_id' => $place->id])->id,
        ]);

        $res = $this->actingAs(operatorOfPlace($place))
            ->getJson("/api/v1/redemptions/{$redemption->id}")
            ->assertOk();

        expect($res->json('data.id'))->toBe((string) $redemption->id)
            ->and($res->json('data'))->not->toHaveKey('code')
            ->and($res->json('data'))->not->toHaveKey('qr_payload')
            ->and($res->content())->not->toContain($redemption->code);
    });

    it('refuses a stranger entirely', function () {
        $place = Place::factory()->active()->create();
        $redemption = Redemption::factory()->create([
            'offer_id' => Offer::factory()->active()->create(['place_id' => $place->id])->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/redemptions/{$redemption->id}")
            ->assertForbidden();
    });
});

describe('GET /me/redemptions', function () {
    it('lists only the caller’s own, newest first, with codes', function () {
        $place = Place::factory()->active()->create();
        $offer = Offer::factory()->active()->create(['place_id' => $place->id]);
        $diner = User::factory()->create();

        // An issued and an expired one — both are the caller's history. They
        // sit on different offers because only ONE may be live per offer.
        $mine = collect([
            Redemption::factory()->create(['offer_id' => $offer->id, 'user_id' => $diner->id]),
            Redemption::factory()->expired()->create([
                'offer_id' => Offer::factory()->active()->create(['place_id' => $place->id])->id,
                'user_id' => $diner->id,
            ]),
        ]);
        // Someone else's — must not appear.
        Redemption::factory()->create(['offer_id' => $offer->id]);

        $rows = $this->actingAs($diner)->getJson('/api/v1/me/redemptions')->assertOk()->json('data');

        expect($rows)->toHaveCount(2)
            ->and(collect($rows)->pluck('id')->all())
            ->toBe($mine->sortByDesc('id')->pluck('id')->map(fn ($id) => (string) $id)->values()->all())
            // Their own codes — a still-live one is what they came back for.
            ->and($rows[0])->toHaveKey('code');
    });

    it('cursor-paginates with the standard envelope', function () {
        $place = Place::factory()->active()->create();
        $diner = User::factory()->create();
        // One per offer: the partial unique index allows a diner exactly one
        // LIVE code per offer, so three against one offer is a fixture the
        // database is right to reject.
        foreach (range(1, 3) as $i) {
            Redemption::factory()->create([
                'offer_id' => Offer::factory()->active()->create(['place_id' => $place->id])->id,
                'user_id' => $diner->id,
            ]);
        }

        $first = $this->actingAs($diner)->getJson('/api/v1/me/redemptions?limit=2')->assertOk();
        expect($first->json('data'))->toHaveCount(2)
            ->and($first->json('meta.pagination.next_cursor'))->not->toBeNull();

        $second = $this->actingAs($diner)
            ->getJson('/api/v1/me/redemptions?limit=2&cursor='.urlencode($first->json('meta.pagination.next_cursor')))
            ->assertOk();

        expect($second->json('data'))->toHaveCount(1)
            ->and(collect($second->json('data'))->pluck('id')->intersect(collect($first->json('data'))->pluck('id')))
            ->toBeEmpty();
    });
});

describe('GET /places/{place}/redemptions', function () {
    it('gives the operator their venue’s log without any codes', function () {
        $place = Place::factory()->active()->create();
        $offer = Offer::factory()->active()->create(['place_id' => $place->id]);
        $redemption = Redemption::factory()->create(['offer_id' => $offer->id]);
        // Another venue's redemption must not appear.
        Redemption::factory()->create([
            'offer_id' => Offer::factory()->active()->create(['place_id' => Place::factory()->active()])->id,
        ]);

        $res = $this->actingAs(operatorOfPlace($place))
            ->getJson("/api/v1/places/{$place->id}/redemptions")
            ->assertOk();

        expect($res->json('data'))->toHaveCount(1)
            ->and($res->json('data.0.id'))->toBe((string) $redemption->id)
            ->and($res->json('data.0'))->not->toHaveKey('code')
            ->and($res->content())->not->toContain($redemption->code);
    });

    it('refuses a non-operator', function () {
        $place = Place::factory()->active()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/places/{$place->id}/redemptions")
            ->assertForbidden();
    });
});

describe('POST /redemptions/verify', function () {
    it('lets the operator honour a code and reports it as not replayed', function () {
        $place = Place::factory()->active()->atPoint(38.7223, -9.1393)->create();
        $operator = operatorOfPlace($place);
        $redemption = Redemption::factory()->withCode('ABCD1234EF')->create([
            'offer_id' => Offer::factory()->active()->create(['place_id' => $place->id])->id,
        ]);

        $this->actingAs($operator)
            ->postJson('/api/v1/redemptions/verify', [
                'code' => 'ABCD-1234-EF',
                'place_id' => $place->id,
                'lat' => 38.7223,
                'lng' => -9.1393,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'redeemed')
            ->assertJsonPath('meta.replayed', false)
            // Even the operator who just verified it does not get the code back.
            ->assertJsonMissingPath('data.code');

        expect($redemption->fresh()->status)->toBe(RedemptionStatus::Redeemed);
    });

    it('reports a second verify as a replay, still 200', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOfPlace($place);
        Redemption::factory()->withCode('ABCD1234EF')->create([
            'offer_id' => Offer::factory()->active()->create(['place_id' => $place->id])->id,
        ]);

        $body = ['code' => 'ABCD1234EF', 'place_id' => $place->id];
        $this->actingAs($operator)->postJson('/api/v1/redemptions/verify', $body)->assertOk();

        $this->actingAs($operator)
            ->postJson('/api/v1/redemptions/verify', $body)
            ->assertOk()
            ->assertJsonPath('meta.replayed', true);

        expect(Redemption::query()->where('status', RedemptionStatus::Redeemed)->count())->toBe(1);
    });

    /*
     * A non-operator is refused BEFORE any redemption state is read, so the
     * endpoint cannot be used to test whether a code is real.
     */
    it('refuses a caller who does not operate the venue', function () {
        $place = Place::factory()->active()->create();
        Redemption::factory()->withCode('ABCD1234EF')->create([
            'offer_id' => Offer::factory()->active()->create(['place_id' => $place->id])->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/redemptions/verify', ['code' => 'ABCD1234EF', 'place_id' => $place->id])
            ->assertForbidden();

        expect(Redemption::firstOrFail()->status)->toBe(RedemptionStatus::Issued);
    });

    it('surfaces the refusal reason in the error envelope', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOfPlace($place);

        $this->actingAs($operator)
            ->postJson('/api/v1/redemptions/verify', ['code' => 'ZZZZ9999ZZ', 'place_id' => $place->id])
            ->assertStatus(404)
            ->assertJsonPath('error.details.reason', 'not_found');
    });

    it('accepts a scanned QR payload as readily as a typed code', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOfPlace($place);
        $diner = User::factory()->create();

        Sanctum::actingAs($diner);
        $issued = $this->postJson('/api/v1/redemptions', [
            'offer_id' => Offer::factory()->active()->create(['place_id' => $place->id])->id,
        ])->assertCreated()->json('data');

        $this->actingAs($operator)
            ->postJson('/api/v1/redemptions/verify', [
                'code' => $issued['qr_payload'],
                'place_id' => $place->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'redeemed');
    });
});

describe('the contract', function () {
    it('validates every redemption payload against redemption.json', function () {
        $place = Place::factory()->active()->create();
        $offer = Offer::factory()->active()->create(['place_id' => $place->id]);
        $diner = User::factory()->create();

        // Holder's view — carries the bearer credentials.
        $issued = $this->actingAs($diner)
            ->postJson('/api/v1/redemptions', ['offer_id' => $offer->id])
            ->assertCreated()->json('data');
        assertMatchesContract($issued, 'redemption');
        expect($issued)->toHaveKey('code');

        // Operator's view of the SAME row — the credentials must be absent, not
        // merely blanked, or the schema would happily allow an empty string.
        $operatorView = $this->actingAs(operatorOfPlace($place))
            ->getJson("/api/v1/redemptions/{$issued['id']}")
            ->assertOk()->json('data');
        assertMatchesContract($operatorView, 'redemption');
        expect($operatorView)->not->toHaveKey('code')
            ->and($operatorView)->not->toHaveKey('qr_payload');
    });

    it('validates a redeemed payload', function () {
        $place = Place::factory()->active()->create();
        $operator = operatorOfPlace($place);
        Redemption::factory()->withCode('ABCD1234EF')->create([
            'offer_id' => Offer::factory()->active()->create(['place_id' => $place->id])->id,
        ]);

        $row = $this->actingAs($operator)
            ->postJson('/api/v1/redemptions/verify', ['code' => 'ABCD1234EF', 'place_id' => $place->id])
            ->assertOk()->json('data');

        assertMatchesContract($row, 'redemption');
        expect($row['redeemed_at'])->not->toBeNull()
            ->and($row['is_live'])->toBeFalse();
    });
});
