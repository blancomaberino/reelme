<?php

use App\Adapters\AdapterRegistry;
use App\Adapters\InstagramAdapter;
use App\Adapters\InstagramGraphAdapter;
use App\Enums\FetchStatus;
use App\Enums\MediaKind;
use App\Enums\Platform;
use App\Enums\ShareStatus;
use App\Jobs\DownloadMedia;
use App\Jobs\ExtractPlaceData;
use App\Jobs\FetchSourcePost;
use App\Jobs\PrepareMedia;
use App\Jobs\ResolvePlace;
use App\Jobs\TranscribeAudio;
use App\Models\MediaAsset;
use App\Models\Place;
use App\Models\Share;
use App\Models\User;
use App\Services\AI\Exceptions\AllEnginesFailed;
use App\Services\Geo\FakeGeocoder;
use App\Services\Media\FfmpegRunner;
use App\Services\Media\Images\PostImageIngestor;
use App\Services\Media\MediaProcessor;
use App\Services\Transcription\TranscriptionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| T-028 — the pipeline failure/review taxonomy (04 §8)
|--------------------------------------------------------------------------
| Pins the terminal outcome of each broken stage. Three distinct buckets the
| product treats very differently:
|   • HARD FAILURE  → status=failed  + failure_reason  (retryable, user notified)
|   • REVIEW PARK   → status=review  + review_reason   (recoverable by a human)
|   • GRACEFUL      → the stage degrades and the pipeline continues (not a stop)
|   • USER DISCARD  → status=rejected + failure_reason=user_discarded
|
| Failures are asserted on the persisted share state, and — where a stage fails
| by throwing — via the job's failed() hook directly (04 gotcha: sync throws
| propagate to the dispatcher, so we don't simulate Horizon).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local_media');
    Storage::fake('local_media_originals');
    config()->set('ai.ollama.url', 'http://ollama.test:11434');
    config()->set('ai.openrouter.url', 'https://openrouter.test/api/v1');
    config()->set('ai.openrouter.api_key', 'sk-test');
    Cache::flush();
    Http::preventStrayRequests();
});

/** A share parked in `fetching` with a caption — ready for the extract stage. */
function taxonomyExtractShare(): Share
{
    $share = Share::factory()->create(['status' => ShareStatus::Fetching]);
    $share->sourcePost->update(['caption' => 'Hand-pulled noodles in Chinatown 🍜']);

    return $share;
}

/*
|--------------------------------------------------------------------------
| HARD FAILURES — status=failed
|--------------------------------------------------------------------------
*/

it('fails media_too_large when the download exceeds the byte cap', function () {
    config()->set('media.max_download_bytes', 10); // the sample video is larger
    useFakeVideoChain();
    $share = Share::factory()->create(['status' => ShareStatus::Fetching]);
    $share->sourcePost->update([
        'platform' => Platform::Instagram,
        'url' => 'https://www.instagram.com/reel/TOOBIG/',
    ]);

    (new DownloadMedia($share->id))->handle(app(AdapterRegistry::class), app(FfmpegRunner::class));

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Failed)
        ->and($share->failure_reason)->toBe('media_too_large');
});

it('fails ffmpeg_error when media preparation throws', function () {
    $share = Share::factory()->create(['status' => ShareStatus::Fetching]);
    $path = "originals/{$share->id}/video.mp4";
    // ffprobe is mocked to throw, so the bytes are never processed — a stub is enough.
    Storage::disk('local_media_originals')->put($path, 'stub-video-bytes');
    MediaAsset::create([
        'source_post_id' => $share->sourcePost->id,
        'kind' => MediaKind::Video,
        'storage_path' => $path,
        'disk' => 'local_media_originals',
        'mime' => 'video/mp4',
        'bytes' => 1000,
        'sha256' => hash('sha256', 'video'),
        'duration_ms' => 5000,
    ]);

    $ffmpeg = Mockery::mock(FfmpegRunner::class);
    $ffmpeg->shouldReceive('probe')->andThrow(new RuntimeException('ffprobe blew up'));

    $job = new PrepareMedia($share->id);
    try {
        $job->handle(app(MediaProcessor::class), $ffmpeg, app(PostImageIngestor::class));
    } catch (RuntimeException $e) {
        $job->failed($e);
    }

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Failed)
        ->and($share->failure_reason)->toBe('ffmpeg_error');
});

it('fails invalid_model_output when both engines produce unusable output', function () {
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(pipelineOllamaChat('not even json')),
        '*/chat/completions' => Http::response(pipelineOpenRouterChat('also not json')),
    ]);
    $share = taxonomyExtractShare();

    $job = new ExtractPlaceData($share->id);
    try {
        $job->handle();
    } catch (AllEnginesFailed $e) {
        $job->failed($e);
    }

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Failed)
        ->and($share->failure_reason)->toBe('invalid_model_output');
});

it('fails cost_cap_exceeded when the cheapest model still exceeds the per-run cap', function () {
    config()->set('ai.max_cost_per_run', 0.0); // any priced model now exceeds it
    Http::fake(['*/api/tags' => Http::response(['models' => []])]);
    $share = taxonomyExtractShare();
    $share->user->forceFill(['preferred_analysis_model' => 'anthropic/claude-sonnet-4'])->save();

    (new ExtractPlaceData($share->id))->handle();

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Failed)
        ->and($share->failure_reason)->toBe('cost_cap_exceeded');
});

it('fails quota_exhausted when the daily budget is spent and local cannot serve', function () {
    config()->set('ai.daily_user_budget', 0.0); // already over budget → no remote spend allowed
    Http::fake(['*/api/tags' => Http::response('', 500)]); // local unhealthy → cannot serve
    $share = taxonomyExtractShare();

    (new ExtractPlaceData($share->id))->handle();

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Failed)
        ->and($share->failure_reason)->toBe('quota_exhausted');
});

/*
|--------------------------------------------------------------------------
| REVIEW PARKS — status=review (recoverable by a human, NOT a failure)
|--------------------------------------------------------------------------
*/

it('parks fetch_unavailable in review when the adapter chain is exhausted', function () {
    config(['ingestion.chains.instagram' => []]); // only the manual fallback remains
    app()->forgetInstance(AdapterRegistry::class);
    $share = Share::factory()->create(['status' => ShareStatus::Fetching]);
    $share->sourcePost->update([
        'platform' => Platform::Instagram,
        'url' => 'https://www.instagram.com/reel/NOPAY/',
        'fetch_status' => FetchStatus::Pending,
    ]);

    (new FetchSourcePost($share->id))->handle(app(AdapterRegistry::class));

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Review)
        ->and($share->failure_reason)->toBe('fetch_unavailable');
});

it('parks fetch_auth_required in review for a private post with no linked account', function () {
    config(['ingestion.chains.instagram' => [InstagramAdapter::class, InstagramGraphAdapter::class]]);
    app()->forgetInstance(AdapterRegistry::class);
    Http::fake(['*instagram.com/api/v1/oembed*' => Http::response('', 401)]); // private
    $share = Share::factory()->create(['status' => ShareStatus::Fetching]);
    $share->sourcePost->update([
        'platform' => Platform::Instagram,
        'url' => 'https://www.instagram.com/reel/PRIVX/',
        'fetch_status' => FetchStatus::Pending,
    ]);

    (new FetchSourcePost($share->id))->handle(app(AdapterRegistry::class));

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Review)
        ->and($share->failure_reason)->toBe('fetch_auth_required');
});

it('parks no_place_extracted in review when the model names no venue', function () {
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(pipelineOllamaChat(pipelineExtractionJson([
            'places.0.name' => null,
            'confidence.overall' => 0.8,
        ]))),
    ]);
    $share = taxonomyExtractShare();

    (new ExtractPlaceData($share->id))->handle();

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Review)
        ->and($share->review_reason)->toBe('no_place_extracted');
});

it('parks ambiguous_place in review when several places match', function () {
    Place::factory()->atPoint(51.5117, -0.1300)->create(['name' => 'Lanzhou Beef Noodle House']);
    Place::factory()->atPoint(51.5117, -0.1300)->create(['name' => 'Lanzhou Beef Noodle House']);
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJambig', 51.5117, -0.1300)));

    (new ResolvePlace(analyzingShare()->id))->handle();

    $share = Share::latest('id')->first();
    expect($share->status)->toBe(ShareStatus::Review)
        ->and($share->review_reason)->toBe('ambiguous_place');
});

it('parks geocode_failed in review when the geocoder finds nothing', function () {
    bindGeocoder(new FakeGeocoder); // nothing seeded → miss

    (new ResolvePlace(analyzingShare()->id))->handle();

    $share = Share::latest('id')->first();
    expect($share->status)->toBe(ShareStatus::Review)
        ->and($share->review_reason)->toBe('geocode_failed');
});

/*
|--------------------------------------------------------------------------
| GRACEFUL DEGRADATION — transcription is best-effort, never a stop
|--------------------------------------------------------------------------
*/

it('does not fail on silent audio — it stores an empty transcript and continues', function () {
    $share = Share::factory()->create(['status' => ShareStatus::Fetching]); // no audio asset

    (new TranscribeAudio($share->id))->handle(app(TranscriptionManager::class));

    $t = $share->sourcePost->fresh()->transcript_json;
    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Fetching) // still in flight, not failed
        ->and($t['text'])->toBe('')
        ->and($t['empty'])->toBeTrue();
});

it('does not fail when the transcriber throws — it degrades to an empty transcript', function () {
    $share = Share::factory()->create(['status' => ShareStatus::Fetching]);
    $path = "media/{$share->id}/audio.wav";
    Storage::disk('local_media')->put($path, 'RIFF....wav');
    MediaAsset::create([
        'source_post_id' => $share->sourcePost->id,
        'kind' => MediaKind::Audio,
        'storage_path' => $path,
        'disk' => 'local_media',
        'mime' => 'audio/wav',
        'bytes' => 11,
        'sha256' => hash('sha256', 'RIFF....wav'),
        'duration_ms' => 5000,
    ]);
    bindFakeTranscription(throws: true); // both drivers fail

    (new TranscribeAudio($share->id))->handle(app(TranscriptionManager::class));

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Fetching)
        ->and($share->sourcePost->fresh()->transcript_json['text'])->toBe('');
});

/*
|--------------------------------------------------------------------------
| USER DISCARD — status=rejected
|--------------------------------------------------------------------------
*/

it('rejects a share the user discards from review with user_discarded', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $share = Share::factory()->review()->create(['user_id' => $user->id]);

    $this->deleteJson("/api/v1/shares/{$share->id}")->assertOk();

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Rejected)
        ->and($share->failure_reason)->toBe('user_discarded');
});
