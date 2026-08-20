<?php

use App\Enums\ClaimStatus;
use App\Enums\PlaceClaimMethod;
use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\User;
use App\Services\Places\PlaceClaimService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/**
 * Restaurant-owner claiming (T-041, 06 §2.1).
 *
 * The organising property under test: **every method proves control of
 * something the PLACE record already lists**, never something the claimant
 * supplied. A claimant who could nominate the phone number or the domain could
 * verify any venue on the map, so those cases get explicit tests rather than
 * being left to the shape of the request object.
 */
function claimant(): User
{
    return User::factory()->create();
}

describe('starting a claim', function () {
    it('sends the code to the number on the place, not one the claimant supplies', function () {
        $place = Place::factory()->active()->providerPhone('+59891238891')->create();
        $user = claimant();

        $res = $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/claim", [
                'method' => 'phone',
                // Ignored by construction — there is no field for it. If this
                // ever starts working, anyone can claim anything.
                'phone' => '+10000000000',
            ])
            ->assertCreated();

        expect($res->json('data.phone_last4'))->toBe('8891')
            // The code itself is never returned; receiving it IS the proof.
            ->and($res->content())->not->toContain('otp');

        $claim = PlaceClaim::firstOrFail();
        expect($claim->method)->toBe(PlaceClaimMethod::Phone)
            ->and($claim->status)->toBe(ClaimStatus::Pending)
            // Stored hashed, so a database read cannot complete a claim.
            ->and($claim->evidence_json['otp'])->not->toBe('');
    });

    it('refuses the phone method when the place has no number on file', function () {
        $place = Place::factory()->active()->create(['phone' => null]);

        $this->actingAs(claimant())
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'phone'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'no_phone_on_file');
    });

    it('issues a website token and says exactly where to publish it', function () {
        $place = Place::factory()->active()->providerWebsite('https://bar-tinta.example/menu')->create();

        $res = $this->actingAs(claimant())
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'website'])
            ->assertCreated();

        expect($res->json('data.token'))->toStartWith('reelmap-verify-')
            // On the place's OWN host, at the well-known path — not a URL the
            // claimant gets to choose.
            ->and($res->json('data.verification_url'))
            ->toBe('https://bar-tinta.example/.well-known/reelmap-verify.txt');
    });

    it('queues a document claim for a human instead of verifying anything', function () {
        $place = Place::factory()->active()->create();

        $res = $this->actingAs(claimant())
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'document'])
            ->assertCreated();

        expect($res->json('data.status'))->toBe('pending')
            ->and($res->json('data.token'))->toBeNull();
    });

    it('replaces an in-flight claim rather than leaving two live codes', function () {
        $place = Place::factory()->active()->providerPhone('+59891238891')->create();
        $user = claimant();

        $this->actingAs($user)->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'phone']);
        $this->actingAs($user)->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'phone']);

        expect(PlaceClaim::where('place_id', $place->id)->where('user_id', $user->id)->count())->toBe(1);
    });

    it('rejects an unknown method', function () {
        $place = Place::factory()->create();

        $this->actingAs(claimant())
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'google_business'])
            ->assertStatus(422);
    });

    it('requires authentication', function () {
        $place = Place::factory()->create();

        $this->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'document'])
            ->assertUnauthorized();
    });
});

describe('phone verification', function () {
    /** Start a phone claim and return [place, user, code]. */
    function phoneClaim(): array
    {
        $place = Place::factory()->create(['phone' => '+59891238891']);
        $user = claimant();
        $code = '123456';

        $claim = PlaceClaim::factory()->phone()->create([
            'place_id' => $place->id,
            'user_id' => $user->id,
            'evidence_json' => [
                'otp' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
                'phone_last4' => '8891',
            ],
        ]);

        return [$place, $user, $code, $claim];
    }

    it('verifies with the right code and grants the operator role', function () {
        [$place, $user, $code] = phoneClaim();

        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/claim/verify", ['method' => 'phone', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        expect($user->fresh()->is_restaurant_owner)->toBeTrue();

        // The working state is cleared — a hashed OTP kept past verification is
        // pure liability.
        expect(PlaceClaim::firstOrFail()->evidence_json)->toBeNull();
    });

    it('counts a wrong code against the attempt budget', function () {
        [$place, $user] = phoneClaim();

        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/claim/verify", ['method' => 'phone', 'code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'code_invalid');

        expect(PlaceClaim::firstOrFail()->evidence_json['attempts'])->toBe(1)
            ->and($user->fresh()->is_restaurant_owner)->toBeFalse();
    });

    it('burns the code after five wrong guesses, so the TTL is not the only bound', function () {
        [$place, $user, $code] = phoneClaim();

        foreach (range(1, 5) as $ignored) {
            $this->actingAs($user)
                ->postJson("/api/v1/places/{$place->id}/claim/verify", ['method' => 'phone', 'code' => '000000'])
                ->assertStatus(422);
        }

        // Even the CORRECT code is refused now.
        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/claim/verify", ['method' => 'phone', 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'too_many_attempts');
    });

    it('refuses an expired code', function () {
        $place = Place::factory()->create(['phone' => '+59891238891']);
        $user = claimant();
        PlaceClaim::factory()->phone()->create([
            'place_id' => $place->id,
            'user_id' => $user->id,
            'evidence_json' => [
                'otp' => Hash::make('123456'),
                'attempts' => 0,
                'expires_at' => now()->subMinute()->toIso8601String(),
            ],
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/claim/verify", ['method' => 'phone', 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'code_expired');
    });

    it('will not verify someone else’s claim', function () {
        [$place, , $code] = phoneClaim();

        // A different user with the right code still has no pending claim of
        // their own, so there is nothing for them to complete.
        $this->actingAs(claimant())
            ->postJson("/api/v1/places/{$place->id}/claim/verify", ['method' => 'phone', 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'no_pending_claim');
    });
});

describe('website verification', function () {
    beforeEach(function () {
        // No DNS in CI, same as the enrichment scraper's tests. Switched back ON
        // for the SSRF case below, which is precisely about the guard.
        config(['places.claims.verify_host' => false]);
    });

    /** @return array{0: Place, 1: User, 2: string} */
    function websiteClaim(string $website = 'https://bar-tinta.example'): array
    {
        $place = Place::factory()->create(['website' => $website]);
        $user = claimant();
        $token = 'reelmap-verify-abc123';

        PlaceClaim::factory()->website()->create([
            'place_id' => $place->id,
            'user_id' => $user->id,
            'evidence_json' => ['token' => $token, 'expires_at' => now()->addHours(72)->toIso8601String()],
        ]);

        return [$place, $user, $token];
    }

    it('verifies when the token is published on the place’s own host', function () {
        [$place, $user, $token] = websiteClaim();
        Http::fake(['bar-tinta.example/.well-known/reelmap-verify.txt' => Http::response($token)]);

        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/claim/verify", ['method' => 'website'])
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        expect($user->fresh()->is_restaurant_owner)->toBeTrue();
    });

    it('fails when the file is absent, without burning the claim', function () {
        [$place, $user] = websiteClaim();
        Http::fake(['*' => Http::response('', 404)]);

        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/claim/verify", ['method' => 'website'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'token_not_found');

        // Still pending, so the operator can publish the file and retry.
        expect(PlaceClaim::firstOrFail()->status)->toBe(ClaimStatus::Pending);
    });

    it('fails when the file holds a different token', function () {
        [$place, $user] = websiteClaim();
        Http::fake(['*' => Http::response('reelmap-verify-someoneelse')]);

        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/claim/verify", ['method' => 'website'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'token_not_found');
    });

    it('refuses to fetch a private-network website — the SSRF guard is load-bearing', function () {
        // `places.website` was extracted from a third party, so it is untrusted
        // input pointed at our own HTTP client. Without the guard, "verify my
        // website" is a request-forgery primitive aimed at anything the API can
        // reach — the cloud metadata endpoint being the classic target.
        //
        // The guard is ON here (the surrounding beforeEach disables it so the
        // other cases need no DNS). A literal IP needs no lookup, so this stays
        // network-free while exercising the real check.
        config(['places.claims.verify_host' => true]);

        [$place, $user] = websiteClaim('http://169.254.169.254');
        Http::fake(['*' => Http::response('reelmap-verify-abc123')]);

        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/claim/verify", ['method' => 'website'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'site_unreachable');

        expect(PlaceClaim::firstOrFail()->status)->toBe(ClaimStatus::Pending)
            ->and($user->fresh()->is_restaurant_owner)->toBeFalse();
    });

    it('rejects the document method at the verify endpoint', function () {
        $place = Place::factory()->create();

        // Not "no pending claim" — a document claim is settled by a human, and
        // saying so at validation is clearer than a misleading downstream error.
        $this->actingAs(claimant())
            ->postJson("/api/v1/places/{$place->id}/claim/verify", ['method' => 'document'])
            ->assertStatus(422);
    });
});

describe('one verified owner per place', function () {
    it('is enforced by the database, not just by application code', function () {
        $place = Place::factory()->create();
        PlaceClaim::factory()->verified()->create(['place_id' => $place->id]);

        // Straight at the table, bypassing every service guard — this is the
        // constraint the whole restaurant program rests on, and two admins
        // approving competing claims in different requests is exactly the race
        // an application-level check misses.
        expect(fn () => PlaceClaim::factory()->verified()->create(['place_id' => $place->id]))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('lets pending and rejected claims pile up so disputes can be escalated', function () {
        $place = Place::factory()->create();

        PlaceClaim::factory()->count(3)->create(['place_id' => $place->id]);
        PlaceClaim::factory()->rejected()->count(2)->create(['place_id' => $place->id]);

        expect(PlaceClaim::where('place_id', $place->id)->count())->toBe(5);
    });

    it('turns away a second claimant on an already-claimed place', function () {
        $place = Place::factory()->active()->create(['phone' => '+59891238891']);
        PlaceClaim::factory()->verified()->create(['place_id' => $place->id]);

        $this->actingAs(claimant())
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'phone'])
            ->assertStatus(409)
            // Says the place is taken, NOT who holds it.
            ->assertJsonPath('error.details.reason', 'already_claimed');
    });

    it('is idempotent for the operator who already holds it', function () {
        $place = Place::factory()->active()->create(['phone' => '+59891238891']);
        $owner = claimant();
        PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $owner->id]);

        $this->actingAs($owner)
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'phone'])
            ->assertStatus(409)
            ->assertJsonPath('error.details.reason', 'already_yours');
    });

    it('closes competing pending claims when one verifies', function () {
        $place = Place::factory()->create(['phone' => '+59891238891']);
        $winner = claimant();
        $loser = PlaceClaim::factory()->create(['place_id' => $place->id]);

        $claim = PlaceClaim::factory()->phone()->create([
            'place_id' => $place->id,
            'user_id' => $winner->id,
            'evidence_json' => [
                'otp' => Hash::make('123456'),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ],
        ]);

        app(PlaceClaimService::class)->verify($claim);

        // Otherwise the admin queue keeps showing work that can no longer be
        // actioned — the place already has an owner.
        expect($loser->fresh()->status)->toBe(ClaimStatus::Rejected)
            ->and($loser->fresh()->reason)->toBe('claimed_by_other');
    });
});

describe('admin review', function () {
    it('grants the operator role on approval and records who decided', function () {
        $claim = PlaceClaim::factory()->create();
        $admin = User::factory()->admin()->create();

        app(PlaceClaimService::class)->approve($claim, $admin);

        $claim->refresh();
        expect($claim->status)->toBe(ClaimStatus::Verified)
            ->and($claim->reviewed_by_user_id)->toBe($admin->id)
            ->and($claim->user->fresh()->is_restaurant_owner)->toBeTrue();
    });

    it('grants nothing on rejection', function () {
        $claim = PlaceClaim::factory()->create();
        $admin = User::factory()->admin()->create();

        app(PlaceClaimService::class)->reject($claim, $admin, 'not_the_operator');

        $claim->refresh();
        expect($claim->status)->toBe(ClaimStatus::Rejected)
            ->and($claim->reason)->toBe('not_the_operator')
            ->and($claim->reviewed_by_user_id)->toBe($admin->id)
            // The whole point of a rejection.
            ->and($claim->user->fresh()->is_restaurant_owner)->toBeFalse();
    });
});

describe('reading your own claim', function () {
    it('returns the caller’s claim', function () {
        $place = Place::factory()->create();
        $user = claimant();
        PlaceClaim::factory()->create(['place_id' => $place->id, 'user_id' => $user->id]);

        $this->actingAs($user)
            ->getJson("/api/v1/places/{$place->id}/claim")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    });

    it('does not reveal that someone else has one pending', function () {
        $place = Place::factory()->create();
        PlaceClaim::factory()->create(['place_id' => $place->id]);

        // Null, not the other person's claim — otherwise this endpoint tells you
        // who is trying to claim which venue.
        $this->actingAs(claimant())
            ->getJson("/api/v1/places/{$place->id}/claim")
            ->assertOk()
            ->assertJsonPath('data', null);
    });

    it('never echoes the stored evidence', function () {
        $place = Place::factory()->create(['phone' => '+59891238891']);
        $user = claimant();
        PlaceClaim::factory()->phone()->create([
            'place_id' => $place->id,
            'user_id' => $user->id,
            'evidence_json' => ['otp' => Hash::make('123456'), 'attempts' => 0, 'phone_last4' => '8891'],
        ]);

        $body = $this->actingAs($user)->getJson("/api/v1/places/{$place->id}/claim")->content();

        expect($body)->not->toContain('otp')
            ->and($body)->not->toContain('attempts')
            // The last four digits ARE fine — they say which line to answer.
            ->and($body)->toContain('8891');
    });
});

describe('the claimed badge', function () {
    it('is false for an unclaimed place and true once verified', function () {
        $place = Place::factory()->create();

        $this->getJson("/api/v1/places/{$place->id}")
            ->assertOk()
            ->assertJsonPath('data.claimed', false);

        PlaceClaim::factory()->verified()->create(['place_id' => $place->id]);

        $this->getJson("/api/v1/places/{$place->id}")
            ->assertOk()
            ->assertJsonPath('data.claimed', true);
    });

    it('stays false while a claim is only pending', function () {
        $place = Place::factory()->create();
        PlaceClaim::factory()->create(['place_id' => $place->id]);

        $this->getJson("/api/v1/places/{$place->id}")
            ->assertOk()
            ->assertJsonPath('data.claimed', false);
    });

    it('does not leak who the operator is', function () {
        $place = Place::factory()->create();
        $owner = User::factory()->create(['username' => 'theoperator']);
        PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $owner->id]);

        // "This venue is claimed" is a useful public signal; who runs it is not.
        expect($this->getJson("/api/v1/places/{$place->id}")->content())
            ->not->toContain('theoperator');
    });
});
