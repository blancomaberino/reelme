<?php

use App\Enums\AnalysisEngine as AnalysisEngineEnum;
use App\Jobs\TranslateTag;
use App\Models\Tag;
use App\Services\AI\Contracts\AnalysisEngine;
use App\Services\AI\Data\GenerationRequest;
use App\Services\AI\Data\GenerationResult;
use App\Services\AI\Exceptions\EngineUnavailable;
use App\Services\AI\LocalEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRemoteEngine;

uses(RefreshDatabase::class);

/** Run the job with a controllable LOCAL (Ollama) engine — the local-first path. */
function runTranslate(Tag $tag, ?string $localRaw, array $locales = ['es']): FakeRemoteEngine
{
    $engine = new FakeRemoteEngine(rawText: $localRaw); // isHealthy() ⇔ rawText !== null
    app()->instance(LocalEngine::class, $engine);
    (new TranslateTag($tag->id, $locales))->handle();

    return $engine;
}

it('fills a missing locale using the local (Ollama) engine — no remote key needed', function () {
    $tag = Tag::factory()->create(['kind' => 'cuisine', 'name' => 'noodles', 'slug' => 'noodles', 'name_i18n' => null]);

    $local = runTranslate($tag, 'Fideos');

    expect($tag->fresh()->name_i18n)->toBe(['es' => 'Fideos'])
        ->and($local->calledWithModels)->toHaveCount(1); // the local engine served it
});

it('falls back to the hosted engine only when local is down and a key is set', function () {
    config(['ai.openrouter.api_key' => 'test-key']);
    $tag = Tag::factory()->create(['kind' => 'cuisine', 'name' => 'noodles', 'slug' => 'noodles', 'name_i18n' => null]);

    app()->instance(LocalEngine::class, new FakeRemoteEngine(rawText: null)); // local unhealthy
    $remote = new FakeRemoteEngine(rawText: 'Fideos');
    app()->instance(AnalysisEngine::class, $remote);

    (new TranslateTag($tag->id, ['es']))->handle();

    expect($tag->fresh()->name_i18n)->toBe(['es' => 'Fideos'])
        ->and($remote->calledWithModels)->toBe(['google/gemini-2.0-flash-001']); // pinned cheap model
});

it('does nothing when no engine is reachable or configured', function () {
    config(['ai.openrouter.api_key' => null]);
    $tag = Tag::factory()->create(['kind' => 'cuisine', 'name' => 'noodles', 'slug' => 'noodles', 'name_i18n' => null]);

    runTranslate($tag, null); // local unhealthy + no key → no-op, no throw

    expect($tag->fresh()->name_i18n)->toBeNull();
});

it('never translates a dish tag (verbatim menu text)', function () {
    $tag = Tag::factory()->create(['kind' => 'dish', 'name' => 'chivito', 'slug' => 'chivito', 'name_i18n' => null]);

    $local = runTranslate($tag, 'Something');

    expect($tag->fresh()->name_i18n)->toBeNull()
        ->and($local->calledWithModels)->toBe([]); // engine never called
});

it('leaves an already-translated locale untouched (no engine call)', function () {
    $tag = Tag::factory()->create(['kind' => 'vibe', 'name' => 'casual', 'slug' => 'casual', 'name_i18n' => ['es' => 'Informal']]);

    $local = runTranslate($tag, 'WRONG');

    expect($tag->fresh()->name_i18n)->toBe(['es' => 'Informal'])
        ->and($local->calledWithModels)->toBe([]);
});

it('sanitizes quotes and trailing punctuation from the reply', function () {
    $tag = Tag::factory()->create(['kind' => 'cuisine', 'name' => 'noodles', 'slug' => 'noodles', 'name_i18n' => null]);

    runTranslate($tag, "«Fideos».\nExtra line");

    expect($tag->fresh()->name_i18n)->toBe(['es' => 'Fideos']);
});

it('drops an implausible (sentence-like) reply and stays untranslated', function () {
    $tag = Tag::factory()->create(['kind' => 'cuisine', 'name' => 'noodles', 'slug' => 'noodles', 'name_i18n' => null]);

    runTranslate($tag, 'I cannot translate this without more context about the restaurant.');

    expect($tag->fresh()->name_i18n)->toBeNull();
});

it('re-throws a transient failure mid-call so the job retries, writing nothing', function () {
    $tag = Tag::factory()->create(['kind' => 'cuisine', 'name' => 'noodles', 'slug' => 'noodles', 'name_i18n' => null]);

    // Healthy at selection time, but the call itself fails (transport blip).
    $flaky = new class implements AnalysisEngine
    {
        public function name(): AnalysisEngineEnum
        {
            return AnalysisEngineEnum::Local;
        }

        public function isHealthy(): bool
        {
            return true;
        }

        public function generate(GenerationRequest $request, ?string $model = null): GenerationResult
        {
            throw new EngineUnavailable('ollama blinked');
        }
    };
    app()->instance(LocalEngine::class, $flaky);

    expect(fn () => (new TranslateTag($tag->id, ['es']))->handle())->toThrow(EngineUnavailable::class);
    expect($tag->fresh()->name_i18n)->toBeNull(); // no partial write
});
