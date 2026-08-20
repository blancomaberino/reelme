<?php

use App\Enums\AnalysisEngine;
use App\Enums\AnalysisStatus;
use App\Enums\ContactFieldSource;
use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Models\AnalysisRun;
use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\Share;
use App\Models\User;
use App\Services\Geo\FakeGeocoder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * SEC-1 / T-117 — venue takeover through a claimant-nominated website.
 *
 * The invariant these tests defend: an automatic place claim (website token,
 * phone OTP) proves ownership only when the contact field it verifies against
 * came from a provider the claimant cannot control. `places.website` and
 * `places.phone` are ALSO writable from the LLM extraction, which the sharer
 * rewrites through PATCH /shares — so provenance, recorded on the row, is what
 * the claim gates on, never the bare presence of a value.
 *
 * Reuses global helpers geoResult()/bindGeocoder() (tests/Helpers).
 */
uses(RefreshDatabase::class);

/** A share parked in `review` with a schema-valid winning run, owned by $user. */
function provenanceReviewShare(User $user): Share
{
    $share = Share::factory()->review()->create(['user_id' => $user->id]);

    $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/extraction/valid.json')), true);
    $payload['confidence']['overall'] = 0.6;

    $run = AnalysisRun::create([
        'share_id' => $share->id,
        'engine' => AnalysisEngine::Local,
        'model' => 'test-model',
        'status' => AnalysisStatus::Succeeded,
        'overall_confidence' => 0.6,
        'result_json' => $payload,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $share->analysis_run_id = $run->id;
    $share->review_reason = 'low_confidence';
    $share->save();

    return $share;
}

describe('the venue-takeover chain is closed end to end', function () {
    it('refuses a website claim on a place whose website was injected via PATCH /shares', function () {
        // The exact SEC-1 chain: a sharer corrects an unmapped real venue's
        // extraction to carry THEIR domain, publishes, and the pin is created with
        // the real venue's Google identity next to the attacker's website.
        $attacker = User::factory()->create();
        Sanctum::actingAs($attacker);
        $share = provenanceReviewShare($attacker);

        // The geocoder resolves the venue to its REAL Google identity — the
        // attacker never controls this; they only control the website field.
        bindGeocoder((new FakeGeocoder)->seed(
            'Jacinto',
            geoResult('ChIJrealJacinto', -34.9084, -56.2079, name: 'Jacinto'),
        ));

        $this->patchJson("/api/v1/shares/{$share->id}", [
            'extraction' => ['places' => [[
                'name' => 'Jacinto',
                'website' => 'https://attacker.example',
            ]]],
            'action' => 'publish',
        ])->assertOk();

        expect($share->fresh()->status)->toBe(ShareStatus::Published);

        // The row is exactly the vulnerable shape — real Google id, attacker's
        // domain — but the domain now carries UNTRUSTED provenance.
        $place = Place::sole();
        expect($place->google_place_id)->toBe('ChIJrealJacinto')
            ->and($place->website)->toBe('https://attacker.example')
            ->and($place->website_source)->toBe(ContactFieldSource::Extraction)
            ->and($place->status)->toBe(PlaceStatus::Active);

        // Step 6 of the exploit — POST claim {website} — is now refused. A token
        // for attacker.example is never issued, so there is nothing to publish.
        $this->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'website'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'no_website_on_file');

        expect(PlaceClaim::count())->toBe(0);
    });
});

describe('provenance, not presence, gates the automatic methods', function () {
    it('refuses a website claim when the website came from the extraction', function () {
        // The docblock invariant, asserted directly: this test FAILS the moment
        // an extraction-sourced website is trusted again.
        $place = Place::factory()->active()->extractionWebsite('https://attacker.example')->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'website'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'no_website_on_file');
    });

    it('allows a website claim when the website came from a provider', function () {
        // The positive control — the guard is provenance-based, not "refuse all".
        $place = Place::factory()->active()->providerWebsite('https://verified.example')->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'website'])
            ->assertCreated()
            ->assertJsonPath('data.verification_url', 'https://verified.example/.well-known/reelmap-verify.txt');
    });

    it('refuses a phone claim when the phone came from the extraction', function () {
        $place = Place::factory()->active()->extractionPhone('+10000000000')->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'phone'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'no_phone_on_file');
    });

    it('allows a phone claim when the phone came from a provider', function () {
        $place = Place::factory()->active()->providerPhone('+59891238891')->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'phone'])
            ->assertCreated()
            ->assertJsonPath('data.phone_last4', '8891');
    });

    it('refuses a website claim when the website was set manually (not a provider)', function () {
        // ContactFieldSource::Manual — an admin-typed / approved-suggestion website
        // is a human's word, not proof the claimant controls the domain.
        $place = Place::factory()->active()->create([
            'website' => 'https://typed-by-admin.example',
            'website_source' => ContactFieldSource::Manual,
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'website'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'no_website_on_file');
    });

    it('refuses a phone claim when the phone was set manually (not a provider)', function () {
        $place = Place::factory()->active()->create([
            'phone' => '+59891238891',
            'phone_source' => ContactFieldSource::Manual,
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'phone'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'no_phone_on_file');
    });

    it('treats a legacy row with no recorded source as untrusted', function () {
        // Backfill invariant: a pre-migration row (website present, source null)
        // must NOT be claimable — "unknown" is untrusted, or the migration hands
        // the attack to everything already in the database.
        $place = Place::factory()->active()->create([
            'website' => 'https://legacy.example',
            'website_source' => null,
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'website'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'no_website_on_file');
    });
});

describe('nobody is dead-ended', function () {
    it('routes a place with only an extraction website to the document method', function () {
        // The website method is unavailable, but the operator can still open a
        // document claim — the refusal message points there, and this is the path.
        $place = Place::factory()->active()->extractionWebsite('https://attacker.example')->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'document'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.method', 'document');
    });
});

describe('only a reviewed, active pin is claimable', function () {
    it('refuses any claim on a pending pin nobody has reviewed', function () {
        $place = Place::factory()->providerWebsite('https://verified.example')->create(['status' => PlaceStatus::Pending]);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'website'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'place_not_claimable');
    });

    it('does not reveal, via the status gate, whether the place is already claimed', function () {
        // A non-active place with an existing verified claim must still answer
        // `place_not_claimable`, not `already_claimed` — the status gate is not an
        // oracle for who, if anyone, holds the place.
        $place = Place::factory()->providerWebsite('https://verified.example')->create(['status' => PlaceStatus::Pending]);
        PlaceClaim::factory()->verified()->create(['place_id' => $place->id]);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'website'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'place_not_claimable');
    });
});

describe('the verified contact is re-checked at completion, not only at start', function () {
    beforeEach(function () {
        config(['places.claims.verify_host' => false]); // no DNS in CI
    });

    it('refuses a website claim whose website changed to a claimant value after start', function () {
        // The start->verify TOCTOU: the claim starts against a provider website
        // (token issued), then the website is moved to the attacker's domain
        // through a non-provider write. Verification must NOT trust the new value.
        $place = Place::factory()->active()->providerWebsite('https://real-owner.example')->create();
        $user = User::factory()->create();

        // Start: allowed, because the website is provider-verified right now.
        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'website'])
            ->assertCreated();
        $token = PlaceClaim::sole()->evidence_json['token'];

        // The website is moved to the attacker's own domain (untrusted provenance).
        $place->forceFill([
            'website' => 'https://attacker.example',
            'website_source' => ContactFieldSource::Extraction,
        ])->save();

        // The attacker hosts the token on their own domain…
        Http::fake(['attacker.example/*' => Http::response($token)]);

        // …and verification is refused: the pinned provider value no longer matches.
        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/claim/verify", ['method' => 'website'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'contact_changed');

        expect($user->fresh()->is_restaurant_owner)->toBeFalse()
            ->and(PlaceClaim::sole()->status->value)->toBe('pending');
    });

    it('refuses a website claim whose website merely lost its provider provenance after start', function () {
        // Same value, provenance downgraded (e.g. a manual overwrite to the same
        // string): the claim is no longer a provider-backed proof.
        $place = Place::factory()->active()->providerWebsite('https://real-owner.example')->create();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'website'])->assertCreated();
        $token = PlaceClaim::sole()->evidence_json['token'];

        $place->forceFill(['website_source' => ContactFieldSource::Manual])->save();
        Http::fake(['*' => Http::response($token)]);

        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/claim/verify", ['method' => 'website'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'contact_changed');
    });

    it('refuses a phone claim whose number changed after start', function () {
        $place = Place::factory()->active()->providerPhone('+59891111111')->create();
        $user = User::factory()->create();

        $code = '123456';
        PlaceClaim::factory()->phone()->create([
            'place_id' => $place->id,
            'user_id' => $user->id,
            'evidence_json' => [
                'otp' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
                'phone' => '+59891111111',
            ],
        ]);

        // Number moves to the attacker's line after the OTP was pinned.
        $place->forceFill(['phone' => '+59899999999', 'phone_source' => ContactFieldSource::Extraction])->save();

        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/claim/verify", ['method' => 'phone', 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'contact_changed');
    });

    it('refuses to complete a website claim on a place merged after the claim started', function () {
        // The status twin of the contact TOCTOU: a claim begun on an Active pin
        // must not COMPLETE on one an admin merged/hid mid-flight (T-117).
        $place = Place::factory()->active()->providerWebsite('https://real-owner.example')->create();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/api/v1/places/{$place->id}/claim", ['method' => 'website'])->assertCreated();
        $token = PlaceClaim::sole()->evidence_json['token'];

        // Admin merges the pin while the claim is pending.
        $place->forceFill(['status' => PlaceStatus::Merged])->save();
        Http::fake(['*' => Http::response($token)]);

        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/claim/verify", ['method' => 'website'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'place_not_claimable');

        expect($user->fresh()->is_restaurant_owner)->toBeFalse();
    });

    it('refuses a legacy pending claim that pinned no contact value', function () {
        // A claim from before this change carries no pinned value; it cannot be
        // revalidated, so it is refused rather than trusted.
        $place = Place::factory()->active()->providerWebsite('https://real-owner.example')->create();
        $user = User::factory()->create();

        PlaceClaim::factory()->website()->create([
            'place_id' => $place->id,
            'user_id' => $user->id,
            'evidence_json' => ['token' => 'reelmap-verify-legacy', 'expires_at' => now()->addHours(72)->toIso8601String()],
        ]);
        Http::fake(['*' => Http::response('reelmap-verify-legacy')]);

        $this->actingAs($user)
            ->postJson("/api/v1/places/{$place->id}/claim/verify", ['method' => 'website'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'contact_changed');
    });
});

describe('provenance travels with the value it describes', function () {
    it('resets a trusted source to untrusted when a raw write changes the value (T-117)', function () {
        // The enforced invariant: a future write that bypasses PlaceEditor and
        // changes `website` without setting `website_source` must not leave the
        // stale `google` stamp on the new (claimant) value — it fails closed.
        $place = Place::factory()->active()->providerWebsite('https://real.example')->create();
        expect($place->websiteIsProviderVerified())->toBeTrue();

        $place->update(['website' => 'https://attacker.example']); // bypasses PlaceEditor

        $place->refresh();
        expect($place->website)->toBe('https://attacker.example')
            ->and($place->website_source)->toBeNull()
            ->and($place->websiteIsProviderVerified())->toBeFalse();
    });

    it('keeps the source when value and source are written together (T-117)', function () {
        // The guard must NOT fire for the curated writers, which co-write both.
        $place = Place::factory()->active()->create(['website' => null, 'website_source' => null]);

        $place->forceFill([
            'website' => 'https://real.example',
            'website_source' => ContactFieldSource::Google,
        ])->save();

        expect($place->fresh()->websiteIsProviderVerified())->toBeTrue();
    });

    it('rejects an out-of-domain provenance value at the database layer (T-117)', function () {
        // The CHECK constraint fails closed at write, so a bad value can never
        // reach the enum cast and 500 the claim gate.
        $place = Place::factory()->create();

        expect(fn () => DB::table('places')
            ->where('id', $place->id)
            ->update(['website_source' => 'gmaps']))
            ->toThrow(QueryException::class);
    });
});
