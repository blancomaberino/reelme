<?php

use App\Enums\AnalysisEngine as AnalysisEngineEnum;
use App\Enums\AnalysisStatus;
use App\Enums\MediaKind;
use App\Enums\ShareStatus;
use App\Jobs\ExtractPlaceData;
use App\Models\AnalysisRun;
use App\Models\MediaAsset;
use App\Models\Share;
use App\Services\AI\CuratedModels;
use App\Services\AI\Exceptions\AllEnginesFailed;
use App\Services\AI\LocalEngine;
use App\Services\AI\ModelRouter;
use App\Services\AI\OllamaClient;
use App\Services\AI\SpendTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeRemoteEngine;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.ollama.url', 'http://ollama.test:11434');
    config()->set('ai.min_confidence', 0.5);
    Storage::fake('local_media');
    Cache::flush();
});

/** The canonical valid payload, optionally overridden via dotted paths. */
function extraction(array $overrides = []): string
{
    $data = json_decode((string) file_get_contents(base_path('tests/Fixtures/extraction/valid.json')), true);
    foreach ($overrides as $path => $value) {
        data_set($data, $path, $value);
    }

    return (string) json_encode($data);
}

/** An Ollama /api/chat body carrying the given assistant content. */
function ollamaChat(string $content): array
{
    return ['message' => ['content' => $content], 'prompt_eval_count' => 10, 'eval_count' => 5];
}

/** Share parked in `fetching` with a caption, transcript, and two keyframes. */
function analyzableShare(): Share
{
    $share = Share::factory()->create(['status' => ShareStatus::Fetching]);

    $post = $share->sourcePost;
    $post->caption = 'Hand-pulled noodles in Chinatown 🍜';
    $post->transcript_json = [
        'language' => 'en',
        'text' => 'they pull the noodles right in front of you',
        'segments' => [
            ['start_ms' => 0, 'end_ms' => 2400, 'text' => 'they pull the noodles'],
            ['start_ms' => 2400, 'end_ms' => 5000, 'text' => 'right in front of you'],
        ],
        'driver' => 'whisper_cpp',
        'empty' => false,
    ];
    $post->save();

    foreach ([0, 1500] as $i => $ms) {
        $path = "media/{$share->id}/frame_{$i}.jpg";
        Storage::disk('local_media')->put($path, "jpeg-bytes-{$i}");
        MediaAsset::create([
            'source_post_id' => $post->id,
            'kind' => MediaKind::Keyframe,
            'storage_path' => $path,
            'disk' => 'local_media',
            'mime' => 'image/jpeg',
            'bytes' => 11,
            'sha256' => hash('sha256', "jpeg-bytes-{$i}"),
            'frame_at_ms' => $ms,
        ]);
    }

    return $share;
}

/** Bind a router whose local engine is faked via Http and whose remote is $remote. */
function bindExtractionRouter(FakeRemoteEngine $remote): void
{
    app()->instance(
        ModelRouter::class,
        new ModelRouter(new LocalEngine(new OllamaClient), $remote, new CuratedModels, new SpendTracker),
    );
}

it('routes a clean high-confidence local extraction onward to ResolvePlace', function () {
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(ollamaChat(extraction())),
    ]);
    bindExtractionRouter(new FakeRemoteEngine);
    $share = analyzableShare();

    (new ExtractPlaceData($share->id))->handle();

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Analyzing)
        ->and($share->review_reason)->toBeNull()
        ->and($share->analysis_run_id)->not->toBeNull();

    $run = $share->analysisRun;
    expect($run->engine)->toBe(AnalysisEngineEnum::Local)
        ->and($run->status)->toBe(AnalysisStatus::Succeeded)
        ->and($run->prompt_version)->toBe('extraction.v5')
        ->and((float) $run->overall_confidence)->toBe(0.91)
        ->and($run->result_json['place']['name'])->toBe('Lanzhou Beef Noodle House');
});

it('tolerantly parses output wrapped in markdown fences and prose', function () {
    $wrapped = "Sure! Here is the JSON:\n```json\n".extraction()."\n```\nHope that helps.";
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(ollamaChat($wrapped)),
    ]);
    bindExtractionRouter(new FakeRemoteEngine);
    $share = analyzableShare();

    (new ExtractPlaceData($share->id))->handle();

    expect($share->fresh()->status)->toBe(ShareStatus::Analyzing)
        ->and(AnalysisRun::count())->toBe(1);
});

it('repairs invalid output on the first repair attempt within one local run', function () {
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::sequence()
            ->push(ollamaChat('{"place":{"name":"missing everything else"}}')) // schema-invalid
            ->push(ollamaChat(extraction())),                                   // repaired
    ]);
    bindExtractionRouter(new FakeRemoteEngine);
    $share = analyzableShare();

    (new ExtractPlaceData($share->id))->handle();

    // A repair re-send stays inside the same engine attempt → still one run row.
    expect(AnalysisRun::count())->toBe(1);
    $run = AnalysisRun::sole();
    expect($run->engine)->toBe(AnalysisEngineEnum::Local)
        ->and($run->status)->toBe(AnalysisStatus::Succeeded);
    expect($share->fresh()->status)->toBe(ShareStatus::Analyzing);
});

it('falls back to OpenRouter after the local engine fails all repair attempts', function () {
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(ollamaChat('{"nope":true}')), // always schema-invalid
    ]);
    bindExtractionRouter(new FakeRemoteEngine(rawText: extraction()));
    $share = analyzableShare();

    (new ExtractPlaceData($share->id))->handle();

    $rows = AnalysisRun::orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->engine)->toBe(AnalysisEngineEnum::Local)
        ->and($rows[0]->status)->toBe(AnalysisStatus::Failed)
        ->and($rows[0]->error)->toStartWith('fallback:invalid_json')
        ->and($rows[0]->prompt_version)->toBe('extraction.v5')
        ->and($rows[1]->engine)->toBe(AnalysisEngineEnum::OpenRouter)
        ->and($rows[1]->status)->toBe(AnalysisStatus::Succeeded);
    expect($share->fresh()->status)->toBe(ShareStatus::Analyzing);
});

it('parks a low-confidence extraction in review', function () {
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(ollamaChat(extraction(['confidence.overall' => 0.6]))),
    ]);
    bindExtractionRouter(new FakeRemoteEngine);
    $share = analyzableShare();

    (new ExtractPlaceData($share->id))->handle();

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Review)
        ->and($share->review_reason)->toBe('low_confidence')
        ->and($share->analysisRun->status)->toBe(AnalysisStatus::Succeeded);
});

it('parks a no-place extraction in review with no_place_extracted', function () {
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(ollamaChat(extraction(['place.name' => null, 'confidence.overall' => 0.8]))),
    ]);
    bindExtractionRouter(new FakeRemoteEngine);
    $share = analyzableShare();

    (new ExtractPlaceData($share->id))->handle();

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Review)
        ->and($share->review_reason)->toBe('no_place_extracted');
});

it('fails the share invalid_model_output when both engines dead-end', function () {
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(ollamaChat('not even json')),
    ]);
    bindExtractionRouter(new FakeRemoteEngine(rawText: 'also not json'));
    $share = analyzableShare();

    $job = new ExtractPlaceData($share->id);
    try {
        $job->handle();
    } catch (AllEnginesFailed $e) {
        $job->failed($e);
    }

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Failed)
        ->and($share->failure_reason)->toBe('invalid_model_output');

    $rows = AnalysisRun::orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn ($r) => $r->status === AnalysisStatus::Failed))->toBeTrue();
});

it('salvages a low-confidence extraction to review when both engines stay under the floor', function () {
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(ollamaChat(extraction(['confidence.overall' => 0.3]))),
    ]);
    bindExtractionRouter(new FakeRemoteEngine(rawText: extraction(['confidence.overall' => 0.3])));
    $share = analyzableShare();

    (new ExtractPlaceData($share->id))->handle();

    $share->refresh();
    // Both engines produced a schema-valid but sub-0.5 result → router dead-ends,
    // but the kept payload is worth a human's review rather than a hard failure.
    expect($share->status)->toBe(ShareStatus::Review)
        ->and($share->review_reason)->toBe('low_confidence')
        ->and($share->analysis_run_id)->not->toBeNull();

    $rows = AnalysisRun::orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn ($r) => $r->status === AnalysisStatus::Failed))->toBeTrue()
        ->and($share->analysisRun->result_json['place']['name'])->toBe('Lanzhou Beef Noodle House');
});

it('fails fast (no retry rows) when the per-run cost cap is exceeded', function () {
    config()->set('ai.max_cost_per_run', 0.0); // any priced model now exceeds the cap
    Http::fake(['*/api/tags' => Http::response(['models' => []])]);
    bindExtractionRouter(new FakeRemoteEngine);
    $share = analyzableShare();
    $share->user->forceFill(['preferred_analysis_model' => 'anthropic/claude-sonnet-4'])->save();

    (new ExtractPlaceData($share->id))->handle();

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Failed)
        ->and($share->failure_reason)->toBe('cost_cap_exceeded')
        ->and(AnalysisRun::where('error', 'like', 'fallback:cost_cap_exceeded%')->count())->toBe(1);
});

it('is idempotent: a re-delivery reuses the succeeded run without a second generation', function () {
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(ollamaChat(extraction())),
    ]);
    bindExtractionRouter(new FakeRemoteEngine);
    $share = analyzableShare();

    (new ExtractPlaceData($share->id))->handle();
    expect(AnalysisRun::count())->toBe(1);

    // Re-arm the share to `fetching` (simulating a retry that re-enters) and run again.
    Share::query()->whereKey($share->id)->update(['status' => ShareStatus::Fetching->value]);
    (new ExtractPlaceData($share->id))->handle();

    expect(AnalysisRun::count())->toBe(1) // no new run — the prior success was reused
        ->and($share->fresh()->status)->toBe(ShareStatus::Analyzing);
});

it('no-ops when the share is not in fetching', function () {
    bindExtractionRouter(new FakeRemoteEngine);
    $share = Share::factory()->create(['status' => ShareStatus::Published]);

    (new ExtractPlaceData($share->id))->handle();

    expect(AnalysisRun::count())->toBe(0)
        ->and($share->fresh()->status)->toBe(ShareStatus::Published);
});
