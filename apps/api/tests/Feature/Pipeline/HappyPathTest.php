<?php

use App\Enums\AnalysisEngine;
use App\Enums\AnalysisStatus;
use App\Enums\FetchStatus;
use App\Enums\MediaKind;
use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Models\MediaAsset;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\User;
use App\Services\Geo\FakeGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| T-028 · M1 exit criterion 1 — full pipeline, share → published
|--------------------------------------------------------------------------
| Drives POST /shares through the REAL Bus::chain (QUEUE_CONNECTION=sync) with a
| media-yielding fake adapter + real ffmpeg + faked transcriber, LLM, and
| geocoder. Proves the stages compose into a published place with complete
| provenance and a correct PostGIS point — no network (preventStrayRequests).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local_media');
    Storage::fake('local_media_originals');
    config()->set('ai.ollama.url', 'http://ollama.test:11434');
    Cache::flush(); // the Ollama health probe caches per-URL — keep tests independent
    Http::preventStrayRequests();
});

it('drives a video share pending → published with full provenance and a real geo point', function () {
    Sanctum::actingAs(User::factory()->create());
    useFakeVideoChain();
    bindFakeTranscription();

    // Local Ollama is healthy and returns a clean high-confidence extraction, so
    // the share auto-publishes without a remote call or a review stop.
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(pipelineOllamaChat(pipelineExtractionJson())),
    ]);
    bindGeocoder((new FakeGeocoder)->seed(
        'Lanzhou Beef Noodle House',
        geoResult('ChIJhappy', 51.5117, -0.1300, name: 'Lanzhou Beef Noodle House'),
    ));

    $this->postJson('/api/v1/shares', ['url' => 'https://www.instagram.com/reel/HAPPY/'])
        ->assertStatus(202);

    $share = Share::latest('id')->first();
    expect($share->status)->toBe(ShareStatus::Published)
        ->and($share->failure_reason)->toBeNull()
        ->and($share->review_reason)->toBeNull()
        ->and($share->published_place_source_id)->not->toBeNull()
        ->and($share->published_at)->not->toBeNull();

    // Source post fetched + attributed to the influencer.
    $post = $share->sourcePost->fresh();
    expect($post->fetch_status)->toBe(FetchStatus::Fetched)
        ->and($post->influencer->handle)->toBe('londonbites')
        ->and($post->transcript_json)->not->toBeNull();

    // Media provenance: the original video plus every derivative ffmpeg produced.
    $kinds = MediaAsset::where('source_post_id', $post->id)->pluck('kind')
        ->map(fn ($k) => $k instanceof MediaKind ? $k->value : $k)->all();
    expect($kinds)->toContain(MediaKind::Video->value)
        ->and($kinds)->toContain(MediaKind::Audio->value)
        ->and($kinds)->toContain(MediaKind::Keyframe->value)
        ->and($kinds)->toContain(MediaKind::Thumbnail->value);

    $video = MediaAsset::where('source_post_id', $post->id)->where('kind', MediaKind::Video->value)->sole();
    expect($video->disk)->toBe('local_media_originals')
        ->and($video->duration_ms)->toBeGreaterThan(0)
        ->and($video->width)->toBe(320)
        ->and($video->height)->toBe(240);
    Storage::disk('local_media_originals')->assertExists($video->storage_path);

    // A succeeded analysis run drives the share.
    $run = $share->analysisRun;
    expect($run->engine)->toBe(AnalysisEngine::Local)
        ->and($run->status)->toBe(AnalysisStatus::Succeeded)
        ->and((float) $run->overall_confidence)->toBe(0.91)
        ->and($run->result_json['places'][0]['name'])->toBe('Lanzhou Beef Noodle House');

    // The place source ties share ↔ place with a frozen snapshot, published live.
    $source = PlaceSource::where('share_id', $share->id)->sole();
    expect($source->id)->toBe($share->published_place_source_id)
        ->and($source->published_at)->not->toBeNull()
        ->and($source->place_id)->not->toBeNull();

    // The place carries the geocoded point — assert the PostGIS geometry directly
    // (ST_X = lng, ST_Y = lat). Reading the geography column raw returns EWKB hex.
    $place = Place::sole();
    expect($place->google_place_id)->toBe('ChIJhappy')
        ->and($place->status)->toBe(PlaceStatus::Pending); // single unverified source

    $coords = DB::selectOne(
        'select ST_X(location::geometry) as lng, ST_Y(location::geometry) as lat from places where id = ?',
        [$place->id],
    );
    expect(round((float) $coords->lat, 4))->toBe(51.5117)
        ->and(round((float) $coords->lng, 4))->toBe(-0.13);

    // The share resource reflects the terminal state + the resolved place.
    $this->getJson("/api/v1/shares/{$share->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.place.name', 'Lanzhou Beef Noodle House');

    // Every stage of the chain ran through the worker and closed its metric
    // (running → completed) with a measured duration (T-093). Asserted on the
    // same drive rather than a second full-ffmpeg run.
    $metrics = $share->stageMetrics()->get();
    expect($metrics->pluck('stage')->all())
        ->toContain('ingest', 'fetch', 'download', 'prepare_media', 'transcribe', 'extract', 'resolve', 'publish')
        ->and($metrics->pluck('status')->unique()->all())->toBe(['completed'])
        ->and($metrics->every(fn ($m) => $m->duration_ms !== null))->toBeTrue();
});
