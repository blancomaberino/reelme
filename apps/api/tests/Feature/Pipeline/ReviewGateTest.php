<?php

use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\User;
use App\Services\Geo\FakeGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| T-028 · M1 exit criterion 4 — the review gate + confirm-to-publish loop
|--------------------------------------------------------------------------
| A low-confidence extraction parks the share in `review` (nothing published
| yet); the owner's PATCH with corrections then drives resolve → publish, records
| the corrections as ground truth, and freezes the corrected snapshot. End to end
| through the HTTP surface on a synchronous chain.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    useFakeInstagram();
    Storage::fake('local_media');
    Storage::fake('local_media_originals');
    config()->set('ai.ollama.url', 'http://ollama.test:11434');
    config()->set('ai.min_publish_confidence', 0.75);
    Cache::flush(); // the Ollama health probe caches per-URL — keep tests independent
    Http::preventStrayRequests();
});

it('parks a low-confidence share in review without publishing anything', function () {
    Sanctum::actingAs(User::factory()->create());
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        // 0.6 clears the router floor (0.5, so the run succeeds) but not the
        // publish floor (0.75), so the gate parks it for a human.
        '*/api/chat' => Http::response(pipelineOllamaChat(pipelineExtractionJson(['confidence.overall' => 0.6]))),
    ]);

    $this->postJson('/api/v1/shares', ['url' => 'https://www.instagram.com/reel/REVIEW/'])
        ->assertStatus(202);

    $share = Share::latest('id')->first();
    expect($share->status)->toBe(ShareStatus::Review)
        ->and($share->review_reason)->toBe('low_confidence')
        ->and($share->published_place_source_id)->toBeNull()
        ->and(PlaceSource::where('share_id', $share->id)->exists())->toBeFalse()
        ->and(Place::count())->toBe(0);

    // The share resource advertises the review stop; a low-confidence park is
    // best-guessable, so the owner can confirm-and-publish as-is (T-098).
    $this->getJson("/api/v1/shares/{$share->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'review')
        ->assertJsonPath('data.can_publish_best_guess', true)
        ->assertJsonPath('data.place', null);
});

it('publishes with corrections recorded when the owner confirms from review', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(pipelineOllamaChat(pipelineExtractionJson(['confidence.overall' => 0.6]))),
    ]);

    $this->postJson('/api/v1/shares', ['url' => 'https://www.instagram.com/reel/CONFIRM/'])
        ->assertStatus(202);
    $share = Share::latest('id')->first();
    expect($share->status)->toBe(ShareStatus::Review);

    // The reviewer corrects the venue name; the confirm drives resolve → publish.
    bindGeocoder((new FakeGeocoder)->seed(
        'Lanzhou Halal Kitchen',
        geoResult('ChIJconfirm', 51.5117, -0.1300, name: 'Lanzhou Halal Kitchen'),
    ));

    $this->patchJson("/api/v1/shares/{$share->id}", [
        'extraction' => ['places' => [['name' => 'Lanzhou Halal Kitchen']]],
        'action' => 'publish',
    ])->assertOk();

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Published)
        ->and($share->user_confirmed)->toBeTrue();

    // The correction is captured as ground truth; the raw model run is untouched.
    $this->assertDatabaseHas('share_corrections', [
        'share_id' => $share->id,
        'field_path' => 'places.0.name',
    ]);
    expect($share->analysisRun->result_json['places'][0]['name'])->toBe('Lanzhou Beef Noodle House');

    // The published snapshot equals the corrected payload, and a user-confirmed
    // publish activates the place immediately.
    $source = PlaceSource::where('share_id', $share->id)->sole();
    expect($source->extraction_snapshot_json['name'])->toBe('Lanzhou Halal Kitchen')
        ->and(Place::sole()->status)->toBe(PlaceStatus::Active);
});
