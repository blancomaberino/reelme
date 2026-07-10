<?php

namespace App\Services\AI;

use App\Enums\AnalysisEngine as AnalysisEngineEnum;
use App\Services\AI\Contracts\AnalysisEngine;
use App\Services\AI\Data\GenerationRequest;
use App\Services\AI\Data\GenerationResult;
use App\Services\AI\Exceptions\EngineUnavailable;

/**
 * Placeholder remote engine bound as `openrouter` until T-020 ships the real
 * OpenRouterClient. It always throws EngineUnavailable — deliberately honest, so
 * pre-T-020 integration exercises the true "remote failed" path rather than a
 * faked success. The container binding is the seam; T-020 swaps this out with no
 * ModelRouter change.
 */
class NullRemoteEngine implements AnalysisEngine
{
    public function name(): AnalysisEngineEnum
    {
        return AnalysisEngineEnum::OpenRouter;
    }

    public function isHealthy(): bool
    {
        return false;
    }

    public function generate(GenerationRequest $request, ?string $model = null): GenerationResult
    {
        throw new EngineUnavailable('OpenRouter engine is not configured yet (T-020).');
    }
}
