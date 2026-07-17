<?php

use App\Jobs\TranslateTag;
use App\Models\Tag;
use App\Services\AI\Contracts\AnalysisEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRemoteEngine;

uses(RefreshDatabase::class);

/** Bind a controllable engine and run the job synchronously. */
function runTranslate(Tag $tag, ?string $rawText, array $locales = ['es']): FakeRemoteEngine
{
    $engine = new FakeRemoteEngine(rawText: $rawText);
    app()->instance(AnalysisEngine::class, $engine);
    (new TranslateTag($tag->id, $locales))->handle($engine);

    return $engine;
}

it('fills a missing locale from the model reply', function () {
    $tag = Tag::factory()->create(['kind' => 'cuisine', 'name' => 'noodles', 'slug' => 'noodles', 'name_i18n' => null]);

    runTranslate($tag, 'Fideos');

    expect($tag->fresh()->name_i18n)->toBe(['es' => 'Fideos']);
});

it('never translates a dish tag (verbatim menu text)', function () {
    $tag = Tag::factory()->create(['kind' => 'dish', 'name' => 'chivito', 'slug' => 'chivito', 'name_i18n' => null]);

    $engine = runTranslate($tag, 'Something');

    expect($tag->fresh()->name_i18n)->toBeNull()
        ->and($engine->calledWithModels)->toBe([]); // engine never called
});

it('leaves an already-translated locale untouched (no engine call)', function () {
    $tag = Tag::factory()->create(['kind' => 'vibe', 'name' => 'casual', 'slug' => 'casual', 'name_i18n' => ['es' => 'Informal']]);

    $engine = runTranslate($tag, 'WRONG');

    expect($tag->fresh()->name_i18n)->toBe(['es' => 'Informal'])
        ->and($engine->calledWithModels)->toBe([]);
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

it('swallows an engine failure, keeping the English fallback', function () {
    $tag = Tag::factory()->create(['kind' => 'cuisine', 'name' => 'noodles', 'slug' => 'noodles', 'name_i18n' => null]);

    // rawText null → FakeRemoteEngine throws EngineUnavailable; the job must not throw.
    runTranslate($tag, null);

    expect($tag->fresh()->name_i18n)->toBeNull();
});
