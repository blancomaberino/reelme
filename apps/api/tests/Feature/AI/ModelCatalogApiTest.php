<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.ollama.url', 'http://ollama.test:11434');
});

it('requires authentication for the models endpoint', function () {
    $this->getJson('/api/v1/analysis/models')->assertUnauthorized();
});

it('returns auto first, live local vision models, then curated remote models', function () {
    Http::fake(['*/api/tags' => Http::response(['models' => [
        ['name' => 'qwen2.5-vl:7b', 'details' => ['family' => 'qwen2']],
        ['name' => 'qwen2.5:14b', 'details' => ['family' => 'qwen2']], // text-only, filtered out
    ]])]);
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/v1/analysis/models')->assertOk();
    $models = $response->json('data.models');

    expect($models[0]['id'])->toBe('auto')
        ->and($models[0]['default'])->toBeTrue();

    $ids = array_column($models, 'id');
    expect($ids)->toContain('qwen2.5-vl:7b')     // vision local surfaced
        ->and($ids)->not->toContain('qwen2.5:14b') // text-only local filtered
        ->and($ids)->toContain('google/gemini-2.0-flash-001'); // curated remote

    $local = collect($models)->firstWhere('id', 'qwen2.5-vl:7b');
    expect($local['engine'])->toBe('local')->and($local['cost_class'])->toBe('free');
});

it('degrades gracefully to no local section when Ollama is down', function () {
    Http::fake(fn () => throw new ConnectionException('down'));
    Sanctum::actingAs(User::factory()->create());

    $models = $this->getJson('/api/v1/analysis/models')->assertOk()->json('data.models');
    $engines = array_column($models, 'engine');

    expect($engines)->not->toContain('local')      // no local entries
        ->and($engines)->toContain('openrouter');  // remote still listed
});

it('persists a valid preferred model via PUT /me/analysis-preference', function () {
    Http::fake(['*/api/tags' => Http::response(['models' => []])]);
    $user = User::factory()->create(['preferred_analysis_model' => null]);
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/me/analysis-preference', ['model' => 'anthropic/claude-sonnet-4'])
        ->assertOk()
        ->assertJsonPath('data.user.preferred_analysis_model', 'anthropic/claude-sonnet-4');

    expect($user->fresh()->preferred_analysis_model)->toBe('anthropic/claude-sonnet-4');
});

it('resets the preference to null when auto is chosen', function () {
    Http::fake(['*/api/tags' => Http::response(['models' => []])]);
    $user = User::factory()->create(['preferred_analysis_model' => 'anthropic/claude-sonnet-4']);
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/me/analysis-preference', ['model' => 'auto'])->assertOk();

    expect($user->fresh()->preferred_analysis_model)->toBeNull();
});

it('rejects an unknown model id with 422', function () {
    Http::fake(['*/api/tags' => Http::response(['models' => []])]);
    Sanctum::actingAs(User::factory()->create());

    // Canonical error envelope: {"error": {code, message, details, ...}} (03 §1).
    $this->putJson('/api/v1/me/analysis-preference', ['model' => 'evil/not-a-real-model'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.model.0', fn ($msg) => is_string($msg));
});
