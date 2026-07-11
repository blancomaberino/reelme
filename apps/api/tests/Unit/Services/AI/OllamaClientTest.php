<?php

use App\Services\AI\Data\GenerationPart;
use App\Services\AI\Data\GenerationRequest;
use App\Services\AI\Exceptions\EngineUnavailable;
use App\Services\AI\Exceptions\GenerationFailed;
use App\Services\AI\OllamaClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('ai.ollama.url', 'http://ollama.test:11434');
    Cache::flush();
});

function client(): OllamaClient
{
    return new OllamaClient;
}

function visionRequest(): GenerationRequest
{
    return new GenerationRequest(
        systemPrompt: 'You extract places.',
        userParts: [
            GenerationPart::text('Caption here'),
            GenerationPart::image('AAAABBBB', 'image/jpeg'),
        ],
        temperature: 0.0,
    );
}

it('parses listModels from an /api/tags fixture', function () {
    Http::fake(['*/api/tags' => Http::response([
        'models' => [
            ['name' => 'qwen2.5-vl:7b', 'size' => 4700000000, 'details' => ['family' => 'qwen2']],
            ['name' => 'qwen2.5:14b', 'size' => 9000000000, 'details' => ['family' => 'qwen2']],
        ],
    ])]);

    $models = client()->listModels();

    expect($models)->toHaveCount(2)
        ->and($models[0])->toMatchArray(['name' => 'qwen2.5-vl:7b', 'size' => 4700000000, 'family' => 'qwen2']);
});

it('sends base64 images and format json on chat, mapping token counts', function () {
    Http::fake(['*/api/chat' => Http::response([
        'message' => ['role' => 'assistant', 'content' => '{"ok":true}'],
        'prompt_eval_count' => 321,
        'eval_count' => 88,
    ])]);

    $result = client()->chat(visionRequest(), 'qwen2.5-vl:7b');

    expect($result->rawText)->toBe('{"ok":true}')
        ->and($result->model)->toBe('qwen2.5-vl:7b')
        ->and($result->inputTokens)->toBe(321)
        ->and($result->outputTokens)->toBe(88)
        ->and($result->costUsd)->toBe(0.0);

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === 'http://ollama.test:11434/api/chat'
            && $body['model'] === 'qwen2.5-vl:7b'
            && $body['format'] === 'json'
            && $body['stream'] === false
            && $body['messages'][1]['images'] === ['AAAABBBB'];
    });
});

it('passes the JSON Schema as `format` for grammar-constrained structured output', function () {
    Http::fake(['*/api/chat' => Http::response(['message' => ['content' => '{"ok":true}']])]);

    $schema = ['type' => 'object', 'required' => ['place'], 'additionalProperties' => false];
    $request = new GenerationRequest(
        systemPrompt: 'extract',
        userParts: [GenerationPart::text('caption')],
        jsonSchema: $schema,
    );

    client()->chat($request, 'gemma4:latest');

    // Small local models can't reliably hit a strict schema from a prompt alone;
    // Ollama grammar-constrains generation when `format` is the schema itself.
    Http::assertSent(fn (Request $r) => $r->data()['format'] === $schema);
});

it('defaults absent token counts to null (cached prompt)', function () {
    Http::fake(['*/api/chat' => Http::response([
        'message' => ['content' => '{}'],
    ])]);

    $result = client()->chat(visionRequest(), 'qwen2.5-vl:7b');

    expect($result->inputTokens)->toBeNull()
        ->and($result->outputTokens)->toBeNull();
});

it('throws GenerationFailed on an empty message', function () {
    Http::fake(['*/api/chat' => Http::response(['message' => ['content' => '']])]);

    client()->chat(visionRequest(), 'm');
})->throws(GenerationFailed::class);

it('throws GenerationFailed on a non-2xx chat response', function () {
    Http::fake(['*/api/chat' => Http::response('boom', 500)]);

    client()->chat(visionRequest(), 'm');
})->throws(GenerationFailed::class);

it('throws EngineUnavailable when the host is unreachable on chat', function () {
    Http::fake(fn () => throw new ConnectionException('refused'));

    client()->chat(visionRequest(), 'm');
})->throws(EngineUnavailable::class);

it('reports healthy on 200 and caches the result (no second HTTP call)', function () {
    Http::fake(['*/api/tags' => Http::response(['models' => []])]);

    expect(client()->healthy())->toBeTrue()
        ->and(client()->healthy())->toBeTrue();

    Http::assertSentCount(1);
});

it('reports unhealthy on a connection error and does not cache the failure', function () {
    Http::fake(fn () => throw new ConnectionException('down'));

    expect(client()->healthy())->toBeFalse();

    // Cache busted on failure → a recovered host is re-probed immediately.
    expect(Cache::has('ollama:healthy'))->toBeFalse();
});
