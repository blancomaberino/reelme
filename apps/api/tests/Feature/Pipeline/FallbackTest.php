<?php

use App\Enums\AnalysisEngine;
use App\Enums\AnalysisStatus;
use App\Enums\ShareStatus;
use App\Models\AnalysisRun;
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
| T-028 · M1 exit criterion 3 — local → remote fallback (all spend booked)
|--------------------------------------------------------------------------
| Drives the REAL ModelRouter from the container (no engine fake): local Ollama
| dead-ends, remote OpenRouter succeeds. Proves the share still reaches published
| and that BOTH engine attempts are persisted as analysis_runs — the failed local
| carrying its fallback reason, the succeeded remote carrying real cost.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
    useFakeInstagram(); // text-only: keep the fallback the unit under test, not media
    Storage::fake('local_media');
    Storage::fake('local_media_originals');
    config()->set('ai.ollama.url', 'http://ollama.test:11434');
    config()->set('ai.openrouter.url', 'https://openrouter.test/api/v1');
    config()->set('ai.openrouter.api_key', 'sk-test');
    config()->set('ai.openrouter.default_model', 'google/gemini-2.0-flash-001');
    Cache::flush(); // the Ollama health probe caches per-URL — keep tests independent
    Http::preventStrayRequests();
});

it('falls back to OpenRouter when the local engine dead-ends, and still publishes', function () {
    // Local is healthy but returns unparseable output on every send (initial +
    // both repairs); remote returns a clean extraction.
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(pipelineOllamaChat('not even json')),
        '*/chat/completions' => Http::response(pipelineOpenRouterChat(pipelineExtractionJson())),
    ]);
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJfallback', 51.5117, -0.1300)));

    $this->postJson('/api/v1/shares', ['url' => 'https://www.instagram.com/reel/FALLBACK/'])
        ->assertStatus(202);

    $share = Share::latest('id')->first();
    expect($share->status)->toBe(ShareStatus::Published);

    $runs = AnalysisRun::where('share_id', $share->id)->orderBy('id')->get();
    expect($runs)->toHaveCount(2)
        ->and($runs[0]->engine)->toBe(AnalysisEngine::Local)
        ->and($runs[0]->status)->toBe(AnalysisStatus::Failed)
        ->and($runs[0]->error)->toStartWith('fallback:invalid_json')
        ->and((float) $runs[0]->cost_usd)->toBe(0.0)
        ->and($runs[1]->engine)->toBe(AnalysisEngine::OpenRouter)
        ->and($runs[1]->status)->toBe(AnalysisStatus::Succeeded)
        ->and((float) $runs[1]->cost_usd)->toBeGreaterThan(0.0);

    // The winning (remote) run drives the published share.
    expect($share->analysis_run_id)->toBe($runs[1]->id);
});

it('escalates a low-confidence local result to the remote engine', function () {
    // Local produces a schema-valid extraction, but under the confidence floor —
    // the router keeps the payload on the failed local run and escalates.
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(pipelineOllamaChat(pipelineExtractionJson(['confidence.overall' => 0.3]))),
        '*/chat/completions' => Http::response(pipelineOpenRouterChat(pipelineExtractionJson())),
    ]);
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJescalate', 51.5117, -0.1300)));

    $this->postJson('/api/v1/shares', ['url' => 'https://www.instagram.com/reel/ESCALATE/'])
        ->assertStatus(202);

    $share = Share::latest('id')->first();
    $runs = AnalysisRun::where('share_id', $share->id)->orderBy('id')->get();

    expect($share->status)->toBe(ShareStatus::Published)
        ->and($runs)->toHaveCount(2)
        ->and($runs[0]->engine)->toBe(AnalysisEngine::Local)
        ->and($runs[0]->status)->toBe(AnalysisStatus::Failed)
        ->and($runs[0]->error)->toStartWith('fallback:low_confidence')
        ->and($runs[1]->engine)->toBe(AnalysisEngine::OpenRouter)
        ->and($runs[1]->status)->toBe(AnalysisStatus::Succeeded);
});
