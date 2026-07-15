<?php

use App\Enums\AnalysisEngine;
use App\Enums\AnalysisStatus;
use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Models\AnalysisRun;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\User;
use App\Services\Geo\FakeGeocoder;
use App\Services\Geo\Geocoder;
use App\Services\Geo\GeocodeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Reuses global helpers: useFakeInstagram() (IngestPipelineTest),
// geoResult()/bindGeocoder() (ResolvePlaceTest).

/** A share parked in `review` carrying a complete, schema-valid winning run. */
function reviewShare(float $confidence = 0.6): Share
{
    $user = User::factory()->create();
    $share = Share::factory()->review()->create(['user_id' => $user->id]);

    $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/extraction/valid.json')), true);
    $payload['confidence']['overall'] = $confidence;

    $run = AnalysisRun::create([
        'share_id' => $share->id,
        'engine' => AnalysisEngine::Local,
        'model' => 'test-model',
        'status' => AnalysisStatus::Succeeded,
        'overall_confidence' => $confidence,
        'result_json' => $payload,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $share->analysis_run_id = $run->id;
    $share->review_reason = 'low_confidence';
    $share->save();

    return $share;
}

it('auto-publishes a high-confidence share end to end (no review stop)', function () {
    useFakeInstagram();
    Sanctum::actingAs(User::factory()->create());

    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response([
            'message' => ['content' => (string) file_get_contents(base_path('tests/Fixtures/extraction/valid.json'))],
            'prompt_eval_count' => 10,
            'eval_count' => 5,
        ]),
    ]);
    app()->instance(Geocoder::class, (new FakeGeocoder)->seed(
        'Lanzhou Beef Noodle House',
        new GeocodeResult('ChIJauto', 'Lanzhou Beef Noodle House', '45 Gerrard St, London', [
            ['long_name' => 'United Kingdom', 'short_name' => 'GB', 'types' => ['country', 'political']],
        ], 51.5117, -0.1300, ['restaurant'], 0.92),
    ));

    $this->postJson('/api/v1/shares', ['url' => 'https://www.instagram.com/reel/AUTO/'])->assertStatus(202);

    $share = Share::latest('id')->first();
    expect($share->status)->toBe(ShareStatus::Published)
        ->and($share->published_place_source_id)->not->toBeNull();

    $place = Place::sole();
    expect($place->status)->toBe(PlaceStatus::Pending) // single unverified auto-published source
        ->and($place->shares_count)->toBe(1)
        ->and(PlaceSource::where('share_id', $share->id)->exists())->toBeTrue();
});

it('applies review corrections, publishes, and freezes the corrected snapshot', function () {
    $share = reviewShare();
    Sanctum::actingAs($share->user);
    bindGeocoder((new FakeGeocoder)->seed(
        'Lanzhou Halal Kitchen',
        geoResult('ChIJreview', 51.5117, -0.1300, name: 'Lanzhou Halal Kitchen'),
    ));

    $this->patchJson("/api/v1/shares/{$share->id}", [
        'extraction' => ['places' => [['name' => 'Lanzhou Halal Kitchen']]],
        'action' => 'publish',
    ])->assertOk();

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Published);

    // Corrections captured as ground truth; the original run payload is untouched.
    $this->assertDatabaseHas('share_corrections', [
        'share_id' => $share->id,
        'field_path' => 'places.0.name',
    ]);
    expect($share->analysisRun->result_json['places'][0]['name'])->toBe('Lanzhou Beef Noodle House');

    // The publish-time snapshot equals the corrected place payload.
    $source = PlaceSource::where('share_id', $share->id)->sole();
    expect($source->extraction_snapshot_json['name'])->toBe('Lanzhou Halal Kitchen');

    // A user-confirmed publish activates the place immediately.
    expect(Place::sole()->status)->toBe(PlaceStatus::Active);
});

it('rejects a correction that breaks the schema with 422 + field details', function () {
    $share = reviewShare();
    Sanctum::actingAs($share->user);

    $this->patchJson("/api/v1/shares/{$share->id}", [
        'extraction' => ['places' => [['price_range' => 9]]], // schema max is 4
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details']]);

    expect($share->fresh()->status)->toBe(ShareStatus::Review)
        ->and($share->fresh()->corrected_extraction_json)->toBeNull();
});

it('attaches to a reviewer-picked candidate and refuses an id outside the offered set', function () {
    $share = reviewShare();
    $a = Place::factory()->atPoint(51.5117, -0.1300)->create(['name' => 'Lanzhou A']);
    $b = Place::factory()->atPoint(51.5117, -0.1300)->create(['name' => 'Lanzhou B']);
    $share->review_reason = 'ambiguous_place';
    $share->review_meta_json = ['candidates' => [
        ['place_id' => $a->id, 'name' => 'Lanzhou A'],
        ['place_id' => $b->id, 'name' => 'Lanzhou B'],
    ]];
    $share->save();
    Sanctum::actingAs($share->user);

    // An id NOT among the offered candidates is refused — no place poisoning.
    $stranger = Place::factory()->atPoint(0.0, 0.0)->create(['name' => 'Stranger']);
    $this->patchJson("/api/v1/shares/{$share->id}", ['place_candidate' => ['place_id' => $stranger->id]])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
    expect(PlaceSource::where('share_id', $share->id)->exists())->toBeFalse()
        ->and($stranger->fresh()->shares_count)->toBe(0);

    // Picking an offered candidate attaches straight to it (no geocode) and publishes.
    $this->patchJson("/api/v1/shares/{$share->id}", [
        'place_candidate' => ['place_id' => $a->id],
        'action' => 'publish',
    ])->assertOk();

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Published)
        ->and(PlaceSource::where('share_id', $share->id)->sole()->place_id)->toBe($a->id)
        ->and($a->fresh()->shares_count)->toBe(1)
        ->and($a->fresh()->status)->toBe(PlaceStatus::Active); // user-confirmed pick
});

it('exposes the published place with coordinates on the share resource', function () {
    $user = User::factory()->create();
    $place = Place::factory()->atPoint(-34.9014, -56.1704)->create(['name' => 'Clara Café']);
    $share = Share::factory()->for($user)->create(['status' => ShareStatus::Published]);
    $source = PlaceSource::factory()->create([
        'place_id' => $place->id, 'share_id' => $share->id, 'source_post_id' => $share->source_post_id,
    ]);
    $share->published_place_source_id = $source->id;
    $share->save();

    Sanctum::actingAs($user);
    $this->getJson("/api/v1/shares/{$share->id}")
        ->assertOk()
        ->assertJsonPath('data.place.name', 'Clara Café')
        ->assertJsonPath('data.place.lat', -34.9014)
        ->assertJsonPath('data.place.lng', -56.1704);
});

it('409s a PATCH on a share that is not in review', function () {
    $user = User::factory()->create();
    $share = Share::factory()->create(['user_id' => $user->id, 'status' => ShareStatus::Analyzing]);
    Sanctum::actingAs($user);

    $this->patchJson("/api/v1/shares/{$share->id}", ['action' => 'publish'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');
});

it('403s a PATCH from a non-owner', function () {
    $share = reviewShare();
    Sanctum::actingAs(User::factory()->create());

    $this->patchJson("/api/v1/shares/{$share->id}", ['action' => 'publish'])
        ->assertStatus(403);
});
