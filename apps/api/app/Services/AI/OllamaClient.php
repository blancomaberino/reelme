<?php

namespace App\Services\AI;

use App\Services\AI\Data\GenerationRequest;
use App\Services\AI\Data\GenerationResult;
use App\Services\AI\Exceptions\EngineUnavailable;
use App\Services\AI\Exceptions\GenerationFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin HTTP wrapper over the Ollama REST API (configurable OLLAMA_URL). Owns the
 * wire mapping only — engine selection and accounting live in ModelRouter. All
 * network access goes through Laravel Http so tests fake it (no live Ollama).
 */
class OllamaClient
{
    /**
     * List locally available models (`GET /api/tags`). Also consumed by T-020's
     * `GET /models` endpoint.
     *
     * @return list<array{name: string, size: int|null, family: string|null}>
     *
     * @throws EngineUnavailable
     */
    public function listModels(): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl())
                ->timeout($this->config('health_timeout', 2))
                ->connectTimeout($this->config('connect_timeout', 5))
                ->get('/api/tags');
        } catch (ConnectionException $e) {
            throw new EngineUnavailable('Ollama unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new EngineUnavailable('Ollama /api/tags returned HTTP '.$response->status());
        }

        /** @var list<array<string, mixed>> $models */
        $models = $response->json('models') ?? [];

        return array_map(static fn (array $m): array => [
            'name' => (string) ($m['name'] ?? ''),
            'size' => isset($m['size']) ? (int) $m['size'] : null,
            'family' => isset($m['details']['family']) ? (string) $m['details']['family'] : null,
        ], $models);
    }

    /**
     * Generate a chat completion (`POST /api/chat`). Images ride as base64 on the
     * user message per Ollama's API; `format: "json"` requests JSON output.
     *
     * @throws EngineUnavailable host unreachable
     * @throws GenerationFailed reached but non-2xx / empty body
     */
    public function chat(GenerationRequest $request, string $model): GenerationResult
    {
        $userMessage = ['role' => 'user', 'content' => $this->textOf($request)];
        $images = $this->imagesOf($request);
        if ($images !== []) {
            $userMessage['images'] = $images;
        }

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $request->systemPrompt],
                $userMessage,
            ],
            'stream' => false,
            // Structured outputs: pass the JSON Schema as `format` so Ollama
            // grammar-constrains generation to a schema-shaped object (small local
            // models can't reliably hit a strict additionalProperties:false schema
            // from a prompt alone). Falls back to plain-JSON mode when no schema is
            // supplied. Post-parse opis validation still runs (T-021).
            'format' => $request->jsonSchema ?? 'json',
            'options' => ['temperature' => $request->temperature],
        ];

        $startedAt = hrtime(true);

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->timeout($this->config('timeout', 180))
                ->connectTimeout($this->config('connect_timeout', 5))
                ->post('/api/chat', $payload);
        } catch (ConnectionException $e) {
            throw new EngineUnavailable('Ollama unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new GenerationFailed('Ollama /api/chat returned HTTP '.$response->status());
        }

        $content = $response->json('message.content');
        if (! is_string($content) || $content === '') {
            throw new GenerationFailed('Ollama returned an empty message.');
        }

        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        // prompt_eval_count is absent when the prompt was cached — treat as null.
        $inputTokens = $response->json('prompt_eval_count');
        $outputTokens = $response->json('eval_count');

        return new GenerationResult(
            rawText: $content,
            model: $model,
            inputTokens: is_numeric($inputTokens) ? (int) $inputTokens : null,
            outputTokens: is_numeric($outputTokens) ? (int) $outputTokens : null,
            costUsd: 0.0,
            durationMs: $durationMs,
        );
    }

    /**
     * Cheap liveness probe used before every routing decision. Short timeout,
     * cached 30 s, never throws — connection failures resolve to false and bust
     * the cache so a recovered host is re-probed promptly.
     */
    public function healthy(): bool
    {
        $cached = Cache::get('ollama:healthy');
        if ($cached !== null) {
            return (bool) $cached;
        }

        try {
            $ok = Http::baseUrl($this->baseUrl())
                ->timeout($this->config('health_timeout', 2))
                ->connectTimeout($this->config('connect_timeout', 5))
                ->get('/api/tags')
                ->successful();
        } catch (Throwable) {
            $ok = false;
        }

        // Only a positive result is cached (for 30 s). A failure is never
        // cached, so a recovered host is re-probed on the very next call.
        if ($ok) {
            Cache::put('ollama:healthy', true, $this->config('health_cache_seconds', 30));
        }

        return $ok;
    }

    private function textOf(GenerationRequest $request): string
    {
        $chunks = [];
        foreach ($request->userParts as $part) {
            if (! $part->isImage() && $part->text !== null) {
                $chunks[] = $part->text;
            }
        }

        return implode("\n\n", $chunks);
    }

    /**
     * @return list<string>
     */
    private function imagesOf(GenerationRequest $request): array
    {
        $images = [];
        foreach ($request->userParts as $part) {
            if ($part->isImage() && $part->imageBase64 !== null) {
                $images[] = $part->imageBase64;
            }
        }

        return $images;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('ai.ollama.url'), '/');
    }

    private function config(string $key, int $default): int
    {
        $value = config("ai.ollama.$key");

        return is_numeric($value) ? (int) $value : $default;
    }
}
