<?php

namespace App\Services\AI;

use App\Enums\AnalysisEngine as AnalysisEngineEnum;
use App\Services\AI\Contracts\AnalysisEngine;
use App\Services\AI\Data\GenerationRequest;
use App\Services\AI\Data\GenerationResult;

/**
 * The Ollama-backed engine: local-first, zero marginal cost. Picks the vision
 * model when the request carries keyframes, otherwise the text model (04 §3).
 */
class LocalEngine implements AnalysisEngine
{
    public function __construct(private readonly OllamaClient $client) {}

    public function name(): AnalysisEngineEnum
    {
        return AnalysisEngineEnum::Local;
    }

    public function isHealthy(): bool
    {
        return $this->client->healthy();
    }

    public function generate(GenerationRequest $request, ?string $model = null): GenerationResult
    {
        return $this->client->chat($request, $model ?? $this->modelFor($request));
    }

    /**
     * The Ollama model this engine would use for a request: the vision model when
     * it carries keyframes, otherwise the text model (04 §3). Public so the
     * router can name the model on the run row before the call is made.
     */
    public function modelFor(GenerationRequest $request): string
    {
        $key = $request->hasImages() ? 'vision_model' : 'text_model';

        return (string) config("ai.ollama.$key");
    }
}
