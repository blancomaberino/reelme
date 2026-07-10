<?php

namespace Tests\Support;

use App\Enums\AnalysisEngine as AnalysisEngineEnum;
use App\Services\AI\Contracts\AnalysisEngine;
use App\Services\AI\Data\GenerationRequest;
use App\Services\AI\Data\GenerationResult;
use App\Services\AI\Exceptions\EngineUnavailable;

/**
 * A controllable stand-in for the (T-020) remote engine so ModelRouter tests can
 * exercise the fallback path without a NullRemoteEngine that always throws.
 */
class FakeRemoteEngine implements AnalysisEngine
{
    /** @var list<string> models it was asked to generate with */
    public array $calledWithModels = [];

    public function __construct(
        private readonly ?string $rawText = '{"remote":true}',
        private readonly string $model = 'remote/model',
    ) {}

    public function name(): AnalysisEngineEnum
    {
        return AnalysisEngineEnum::OpenRouter;
    }

    public function isHealthy(): bool
    {
        return $this->rawText !== null;
    }

    public function generate(GenerationRequest $request, ?string $model = null): GenerationResult
    {
        $this->calledWithModels[] = $model ?? '(default)';

        if ($this->rawText === null) {
            throw new EngineUnavailable('fake remote is down');
        }

        return new GenerationResult(
            rawText: $this->rawText,
            model: $model ?? $this->model,
            inputTokens: 100,
            outputTokens: 40,
            costUsd: 0.004,
            durationMs: 50,
        );
    }
}
