<?php

use App\Enums\AnalysisEngine as AnalysisEngineEnum;
use App\Enums\AnalysisStatus;
use App\Models\AnalysisRun;
use App\Models\Share;
use App\Models\User;
use App\Services\AI\Contracts\AnalysisEngine;
use App\Services\AI\CuratedModels;
use App\Services\AI\Data\GenerationPart;
use App\Services\AI\Data\GenerationRequest;
use App\Services\AI\Data\ValidationOutcome;
use App\Services\AI\Exceptions\AllEnginesFailed;
use App\Services\AI\Exceptions\CostCapExceeded;
use App\Services\AI\Exceptions\QuotaExhausted;
use App\Services\AI\LocalEngine;
use App\Services\AI\ModelRouter;
use App\Services\AI\OllamaClient;
use App\Services\AI\SpendTracker;
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
    config()->set('ai.max_cost_per_run', 0.10);
    config()->set('ai.daily_user_budget', 0.50);
    Cache::flush();
});

function guardedRouter(AnalysisEngine $remote, SpendTracker $spend): ModelRouter
{
    return new ModelRouter(new LocalEngine(new OllamaClient), $remote, new CuratedModels, $spend);
}

function guardRequest(): GenerationRequest
{
    return new GenerationRequest(
        systemPrompt: 'extract',
        userParts: [GenerationPart::text('caption'), GenerationPart::image('QkFTRTY0')],
    );
}

function localHealthyChat(): void
{
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response(['message' => ['content' => '{"local":true}'], 'prompt_eval_count' => 10, 'eval_count' => 5]),
    ]);
}

$valid = fn (float $c) => fn (string $raw) => ValidationOutcome::valid(['place' => ['name' => 'X']], $c);

it('downgrades a too-expensive pinned model to the cheapest curated model', function () use ($valid) {
    localHealthyChat();
    config()->set('ai.max_cost_per_run', 0.01); // Sonnet (~$0.025) exceeds; Gemini (~$0.0007) fits.
    $share = Share::factory()->create();
    $remote = new FakeRemoteEngine;

    $run = guardedRouter($remote, new SpendTracker)->route($share, guardRequest(), $valid(0.9), 'anthropic/claude-sonnet-4');

    expect($run->status)->toBe(AnalysisStatus::Succeeded)
        ->and($remote->calledWithModels)->toBe(['google/gemini-2.0-flash-001']);
});

it('fails with cost_cap_exceeded when even the cheapest model is over the cap', function () use ($valid) {
    config()->set('ai.max_cost_per_run', 0.0000001); // nothing fits
    $share = Share::factory()->create();

    $route = fn () => guardedRouter(new FakeRemoteEngine, new SpendTracker)
        ->route($share, guardRequest(), $valid(0.9), 'anthropic/claude-sonnet-4');

    expect($route)->toThrow(CostCapExceeded::class);

    $run = AnalysisRun::firstOrFail();
    expect($run->engine)->toBe(AnalysisEngineEnum::OpenRouter)
        ->and($run->status)->toBe(AnalysisStatus::Failed)
        ->and($run->error)->toStartWith('fallback:cost_cap_exceeded');
});

it('forces local-only when over the daily budget (no remote call)', function () use ($valid) {
    localHealthyChat();
    $share = Share::factory()->create();
    $spend = new SpendTracker;
    $spend->record($share->user_id, 0.60); // over the $0.50 budget
    $remote = new FakeRemoteEngine;

    $run = guardedRouter($remote, $spend)->route($share, guardRequest(), $valid(0.9));

    expect($run->engine)->toBe(AnalysisEngineEnum::Local)
        ->and($run->status)->toBe(AnalysisStatus::Succeeded)
        ->and($remote->calledWithModels)->toBe([]); // remote never attempted
});

it('parks the share (QuotaExhausted) when over budget and local is unavailable', function () use ($valid) {
    Http::fake(fn () => throw new ConnectionException('down'));
    $share = Share::factory()->create();
    $spend = new SpendTracker;
    $spend->record($share->user_id, 0.60);

    $route = fn () => guardedRouter(new FakeRemoteEngine, $spend)->route($share, guardRequest(), $valid(0.9));

    expect($route)->toThrow(QuotaExhausted::class);

    // The local health-miss is still recorded for the audit trail.
    $run = AnalysisRun::firstOrFail();
    expect($run->engine)->toBe(AnalysisEngineEnum::Local)
        ->and($run->error)->toStartWith('fallback:ollama_unreachable');
});

it('records remote spend against the daily budget after a successful remote run', function () {
    localHealthyChat();
    $share = Share::factory()->create();
    $spend = new SpendTracker;

    // Local returns invalid → remote (Fake, cost 0.004) succeeds.
    guardedRouter(new FakeRemoteEngine, $spend)->route($share, guardRequest(), function (string $raw) {
        return str_contains($raw, 'remote')
            ? ValidationOutcome::valid(['place' => ['name' => 'Y']], 0.9)
            : ValidationOutcome::invalid();
    });

    expect($spend->todaySpendUsd($share->user_id))->toBe(0.004);
});

it('records spend for a billed remote run even when it fails validation', function () {
    localHealthyChat();
    $share = Share::factory()->create();
    $spend = new SpendTracker;

    // Local AND remote both fail validation — but the remote call was billed.
    $route = fn () => guardedRouter(new FakeRemoteEngine, $spend)
        ->route($share, guardRequest(), fn (string $raw) => ValidationOutcome::invalid());

    expect($route)->toThrow(AllEnginesFailed::class);
    expect($spend->todaySpendUsd($share->user_id))->toBe(0.004); // charged despite failure
});

it('routes a pinned local model through the local engine, not remote', function () use ($valid) {
    localHealthyChat();
    $user = User::factory()->create(['preferred_analysis_model' => 'qwen2.5-vl:7b']);
    $share = Share::factory()->for($user)->create();
    $remote = new FakeRemoteEngine;

    $run = guardedRouter($remote, new SpendTracker)->route($share, guardRequest(), $valid(0.9));

    expect($run->engine)->toBe(AnalysisEngineEnum::Local)
        ->and($run->model)->toBe('qwen2.5-vl:7b')
        ->and($remote->calledWithModels)->toBe([]); // remote never attempted
});
