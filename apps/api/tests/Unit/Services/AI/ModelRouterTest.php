<?php

use App\Enums\AnalysisEngine as AnalysisEngineEnum;
use App\Enums\AnalysisStatus;
use App\Models\AnalysisRun;
use App\Models\Share;
use App\Models\User;
use App\Services\AI\Contracts\AnalysisEngine;
use App\Services\AI\Data\GenerationPart;
use App\Services\AI\Data\GenerationRequest;
use App\Services\AI\Data\ValidationOutcome;
use App\Services\AI\Exceptions\AllEnginesFailed;
use App\Services\AI\LocalEngine;
use App\Services\AI\ModelRouter;
use App\Services\AI\NullRemoteEngine;
use App\Services\AI\OllamaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeRemoteEngine;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.ollama.url', 'http://ollama.test:11434');
    config()->set('ai.min_confidence', 0.5);
    Cache::flush();
});

function genRequest(): GenerationRequest
{
    return new GenerationRequest(
        systemPrompt: 'extract',
        userParts: [GenerationPart::text('caption'), GenerationPart::image('QkFTRTY0')],
    );
}

function router(AnalysisEngine $remote): ModelRouter
{
    return new ModelRouter(new LocalEngine(new OllamaClient), $remote);
}

function fakeHealthy(): void
{
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(['message' => ['content' => '{"local":true}'], 'prompt_eval_count' => 10, 'eval_count' => 5]),
    ]);
}

$valid = fn (float $c) => fn (string $raw) => ValidationOutcome::valid(['place' => ['name' => 'X']], $c);
$invalid = fn () => fn (string $raw) => ValidationOutcome::invalid();

it('routes a healthy local run with valid high-confidence output to a single succeeded local row', function () use ($valid) {
    fakeHealthy();
    $share = Share::factory()->create();

    $run = router(new FakeRemoteEngine)->route($share, genRequest(), $valid(0.9));

    expect($run->engine)->toBe(AnalysisEngineEnum::Local)
        ->and($run->status)->toBe(AnalysisStatus::Succeeded)
        ->and((float) $run->cost_usd)->toBe(0.0)
        ->and($run->result_json)->toBe(['place' => ['name' => 'X']])
        ->and((float) $run->overall_confidence)->toBe(0.9)
        ->and($run->model)->toBe('qwen2.5-vl:7b');

    expect(AnalysisRun::count())->toBe(1);
});

it('falls back to remote when Ollama is unreachable, recording the local reason', function () use ($valid) {
    Http::fake(fn () => throw new ConnectionException('down'));
    $share = Share::factory()->create();

    $run = router(new FakeRemoteEngine)->route($share, genRequest(), $valid(0.9));

    expect($run->engine)->toBe(AnalysisEngineEnum::OpenRouter)
        ->and($run->status)->toBe(AnalysisStatus::Succeeded);

    $rows = AnalysisRun::orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->engine)->toBe(AnalysisEngineEnum::Local)
        ->and($rows[0]->status)->toBe(AnalysisStatus::Failed)
        ->and($rows[0]->error)->toStartWith('fallback:ollama_unreachable')
        ->and($rows[1]->engine)->toBe(AnalysisEngineEnum::OpenRouter)
        ->and($rows[1]->status)->toBe(AnalysisStatus::Succeeded);
});

it('falls back to remote when local output fails validation (invalid_json)', function () {
    fakeHealthy();
    $share = Share::factory()->create();

    $run = router(new FakeRemoteEngine)->route($share, genRequest(), function (string $raw) {
        // Local invalid, remote valid — decide by content.
        return str_contains($raw, 'remote')
            ? ValidationOutcome::valid(['place' => ['name' => 'Y']], 0.8)
            : ValidationOutcome::invalid();
    });

    expect($run->engine)->toBe(AnalysisEngineEnum::OpenRouter)
        ->and($run->status)->toBe(AnalysisStatus::Succeeded);

    $local = AnalysisRun::where('engine', AnalysisEngineEnum::Local->value)->firstOrFail();
    expect($local->status)->toBe(AnalysisStatus::Failed)
        ->and($local->error)->toStartWith('fallback:invalid_json')
        ->and($local->result_json)->toBeNull();
});

it('escalates to remote when local confidence is below the floor (low_confidence)', function () {
    fakeHealthy();
    $share = Share::factory()->create();

    $run = router(new FakeRemoteEngine)->route($share, genRequest(), function (string $raw) {
        return str_contains($raw, 'remote')
            ? ValidationOutcome::valid(['place' => ['name' => 'Y']], 0.9)
            : ValidationOutcome::valid(['place' => ['name' => 'X']], 0.4);
    });

    expect($run->engine)->toBe(AnalysisEngineEnum::OpenRouter);

    $local = AnalysisRun::where('engine', AnalysisEngineEnum::Local->value)->firstOrFail();
    // The low-confidence local output was schema-valid, so it is kept for debugging.
    expect($local->status)->toBe(AnalysisStatus::Failed)
        ->and($local->error)->toStartWith('fallback:low_confidence')
        ->and($local->result_json)->toBe(['place' => ['name' => 'X']])
        ->and((float) $local->overall_confidence)->toBe(0.4);
});

it('sends a pinned model straight to remote with no local attempt', function () use ($valid) {
    fakeHealthy();
    $user = User::factory()->create(['preferred_analysis_model' => 'anthropic/claude-sonnet-4']);
    $share = Share::factory()->for($user)->create();
    $remote = new FakeRemoteEngine;

    $run = router($remote)->route($share, genRequest(), $valid(0.9));

    expect($run->engine)->toBe(AnalysisEngineEnum::OpenRouter)
        ->and($remote->calledWithModels)->toBe(['anthropic/claude-sonnet-4'])
        ->and(AnalysisRun::where('engine', AnalysisEngineEnum::Local->value)->count())->toBe(0);

    // No /api/chat call was made (local skipped entirely).
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/chat'));
});

it('throws AllEnginesFailed with a persisted row per attempt when the remote stub also fails', function () {
    fakeHealthy();
    $share = Share::factory()->create();

    // Local returns text but validation always fails → invalid_json → remote
    // (NullRemoteEngine) throws → AllEnginesFailed.
    $route = fn () => router(new NullRemoteEngine)->route(
        $share,
        genRequest(),
        fn (string $raw) => ValidationOutcome::invalid(),
    );

    expect($route)->toThrow(AllEnginesFailed::class);

    $rows = AnalysisRun::orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->engine)->toBe(AnalysisEngineEnum::Local)
        ->and($rows[0]->status)->toBe(AnalysisStatus::Failed)
        ->and($rows[1]->engine)->toBe(AnalysisEngineEnum::OpenRouter)
        ->and($rows[1]->status)->toBe(AnalysisStatus::Failed)
        ->and($rows[1]->error)->toStartWith('fallback:ollama_unreachable');
});
