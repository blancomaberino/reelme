<?php

namespace App\Services\AI;

use App\Enums\AnalysisEngine as AnalysisEngineEnum;
use App\Services\AI\Contracts\AnalysisEngine;
use App\Services\AI\Data\GenerationPart;
use App\Services\AI\Data\GenerationRequest;
use App\Services\AI\Data\GenerationResult;
use App\Services\AI\Exceptions\EngineUnavailable;
use App\Services\AI\Exceptions\GenerationFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The remote (hosted) analysis engine: OpenRouter chat-completions. Drops into
 * ModelRouter as the `openrouter` binding (replacing T-019's NullRemoteEngine).
 * Keyframes ride as base64 data-URI image parts (R2 keyframe URLs are private);
 * cost comes inline from `usage.cost` because we request `usage.include`.
 */
class OpenRouterClient implements AnalysisEngine
{
    public function __construct(private readonly CuratedModels $curated) {}

    public function name(): AnalysisEngineEnum
    {
        return AnalysisEngineEnum::OpenRouter;
    }

    /** Healthy iff an API key is configured — the only precondition to a call. */
    public function isHealthy(): bool
    {
        return $this->apiKey() !== '';
    }

    public function generate(GenerationRequest $request, ?string $model = null): GenerationResult
    {
        $model ??= (string) config('ai.openrouter.default_model');

        if ($this->apiKey() === '') {
            throw new EngineUnavailable('OpenRouter API key is not configured.');
        }

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $request->systemPrompt],
                ['role' => 'user', 'content' => $this->userContent($request)],
            ],
            'temperature' => $request->temperature,
            // Ask for inline cost in the usage object (04 §3 gotcha).
            'usage' => ['include' => true],
        ];

        if ($request->jsonSchema !== null) {
            $payload['response_format'] = $this->responseFormat($model, $request->jsonSchema);
        }

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->withToken($this->apiKey())
                ->withHeaders([
                    'HTTP-Referer' => (string) config('ai.openrouter.referer'),
                    'X-Title' => (string) config('ai.openrouter.title'),
                ])
                ->timeout((int) config('ai.openrouter.timeout', 180))
                ->connectTimeout(5)
                ->post('/chat/completions', $payload);
        } catch (ConnectionException $e) {
            throw new EngineUnavailable('OpenRouter unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new EngineUnavailable('OpenRouter auth rejected (HTTP '.$response->status().').');
        }

        if ($response->failed()) {
            throw new GenerationFailed('OpenRouter returned HTTP '.$response->status());
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new GenerationFailed('OpenRouter returned an empty message.');
        }

        $promptTokens = $response->json('usage.prompt_tokens');
        $completionTokens = $response->json('usage.completion_tokens');
        $cost = $response->json('usage.cost');

        return new GenerationResult(
            rawText: $content,
            model: (string) ($response->json('model') ?? $model),
            inputTokens: is_numeric($promptTokens) ? (int) $promptTokens : null,
            outputTokens: is_numeric($completionTokens) ? (int) $completionTokens : null,
            costUsd: is_numeric($cost) ? (float) $cost : 0.0,
        );
    }

    /**
     * OpenRouter multimodal content: a text part plus one image_url part per
     * keyframe, each a base64 data URI.
     *
     * @return list<array<string, mixed>>
     */
    private function userContent(GenerationRequest $request): array
    {
        $parts = [];
        foreach ($request->userParts as $part) {
            $parts[] = $part->isImage()
                ? ['type' => 'image_url', 'image_url' => ['url' => $this->dataUri($part)]]
                : ['type' => 'text', 'text' => (string) $part->text];
        }

        return $parts;
    }

    private function dataUri(GenerationPart $part): string
    {
        return 'data:'.($part->mime ?? 'image/jpeg').';base64,'.$part->imageBase64;
    }

    /**
     * Strict json_schema when the model supports it (config flag), else a plain
     * json_object; callers schema-validate downstream either way.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function responseFormat(string $model, array $schema): array
    {
        if ($this->curated->supportsJsonSchema($model)) {
            return [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'ReelmapExtraction', 'schema' => $schema, 'strict' => true],
            ];
        }

        return ['type' => 'json_object'];
    }

    private function apiKey(): string
    {
        return (string) config('ai.openrouter.api_key');
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('ai.openrouter.url'), '/');
    }
}
