<?php

namespace App\Services\AI\Contracts;

use App\Enums\AnalysisEngine as AnalysisEngineEnum;
use App\Services\AI\Data\GenerationRequest;
use App\Services\AI\Data\GenerationResult;
use App\Services\AI\Exceptions\EngineUnavailable;
use App\Services\AI\Exceptions\GenerationFailed;

/**
 * A pluggable LLM backend the ModelRouter can call. The local (Ollama) and
 * remote (OpenRouter, T-020) engines both implement this; the router treats them
 * uniformly and swaps the remote binding without changing routing logic.
 */
interface AnalysisEngine
{
    public function name(): AnalysisEngineEnum;

    /** Never throws — a health probe failure is a `false`, not an exception. */
    public function isHealthy(): bool;

    /**
     * @throws EngineUnavailable the engine could not be reached
     * @throws GenerationFailed the engine was reached but the call failed
     */
    public function generate(GenerationRequest $request, ?string $model = null): GenerationResult;
}
