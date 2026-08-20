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
use Illuminate\Foundation\Testing\RefreshDatabase;
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
