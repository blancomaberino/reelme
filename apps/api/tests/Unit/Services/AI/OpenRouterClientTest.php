<?php

use App\Enums\AnalysisEngine as AnalysisEngineEnum;
use App\Services\AI\CuratedModels;
use App\Services\AI\Data\GenerationPart;
use App\Services\AI\Data\GenerationRequest;
use App\Services\AI\Exceptions\EngineUnavailable;
use App\Services\AI\Exceptions\GenerationFailed;
use App\Services\AI\OpenRouterClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('ai.openrouter.url', 'https://openrouter.test/api/v1');
    config()->set('ai.openrouter.api_key', 'sk-test');
    config()->set('ai.openrouter.default_model', 'google/gemini-2.0-flash-001');
    Http::preventStrayRequests();
});

function openRouter(): OpenRouterClient
{
    return new OpenRouterClient(new CuratedModels);
}

function orRequest(?array $schema = null): GenerationRequest
{
    return new GenerationRequest(
        systemPrompt: 'extract places',
        userParts: [GenerationPart::text('caption'), GenerationPart::image('QUJD', 'image/jpeg')],
        jsonSchema: $schema,
    );
}

function orResponse(array $overrides = []): array
{
    return array_replace([
        'model' => 'google/gemini-2.0-flash-001',
        'choices' => [['message' => ['content' => '{"ok":true}']]],
        'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 300, 'cost' => 0.0123],
    ], $overrides);
}

it('reports the engine name and health from the API key', function () {
    expect(openRouter()->name())->toBe(AnalysisEngineEnum::OpenRouter)
        ->and(openRouter()->isHealthy())->toBeTrue();

    config()->set('ai.openrouter.api_key', '');
    expect(openRouter()->isHealthy())->toBeFalse();
});

it('sends base64 data-URI image parts and parses content + cost from usage', function () {
    Http::fake(['*/chat/completions' => Http::response(orResponse())]);

    $result = openRouter()->generate(orRequest(), 'google/gemini-2.0-flash-001');

    expect($result->rawText)->toBe('{"ok":true}')
        ->and($result->inputTokens)->toBe(1200)
        ->and($result->outputTokens)->toBe(300)
        ->and($result->costUsd)->toBe(0.0123);

    Http::assertSent(function (Request $request) {
        $body = $request->data();
        $userParts = $body['messages'][1]['content'];

        return $request->hasHeader('Authorization', 'Bearer sk-test')
            && $request->hasHeader('X-Title', 'Reelmap')
            && $body['usage']['include'] === true
            && $userParts[0] === ['type' => 'text', 'text' => 'caption']
            && $userParts[1]['type'] === 'image_url'
            && $userParts[1]['image_url']['url'] === 'data:image/jpeg;base64,QUJD';
    });
});

it('requests strict json_schema for a model that supports it', function () {
    Http::fake(['*/chat/completions' => Http::response(orResponse())]);

    openRouter()->generate(orRequest(['type' => 'object']), 'google/gemini-2.0-flash-001');

    Http::assertSent(function (Request $request) {
        $rf = $request->data()['response_format'];

        return $rf['type'] === 'json_schema'
            && $rf['json_schema']['strict'] === true
            && $rf['json_schema']['name'] === 'ReelmapExtraction';
    });
});

it('falls back to json_object for a model without json_schema support', function () {
    Http::fake(['*/chat/completions' => Http::response(orResponse(['model' => 'qwen/qwen2.5-vl-72b-instruct']))]);

    openRouter()->generate(orRequest(['type' => 'object']), 'qwen/qwen2.5-vl-72b-instruct');

    Http::assertSent(fn (Request $request) => $request->data()['response_format'] === ['type' => 'json_object']);
});

it('throws EngineUnavailable on a 401 (auth rejected)', function () {
    Http::fake(['*/chat/completions' => Http::response('nope', 401)]);

    openRouter()->generate(orRequest(), 'google/gemini-2.0-flash-001');
})->throws(EngineUnavailable::class);

it('throws GenerationFailed on a 500', function () {
    Http::fake(['*/chat/completions' => Http::response('boom', 500)]);

    openRouter()->generate(orRequest(), 'google/gemini-2.0-flash-001');
})->throws(GenerationFailed::class);

it('throws GenerationFailed on an empty message', function () {
    Http::fake(['*/chat/completions' => Http::response(orResponse(['choices' => [['message' => ['content' => '']]]]))]);

    openRouter()->generate(orRequest(), 'google/gemini-2.0-flash-001');
})->throws(GenerationFailed::class);

it('throws EngineUnavailable when no API key is configured', function () {
    config()->set('ai.openrouter.api_key', '');

    openRouter()->generate(orRequest(), 'google/gemini-2.0-flash-001');
})->throws(EngineUnavailable::class);
