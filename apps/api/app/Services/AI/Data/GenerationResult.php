<?php

namespace App\Services\AI\Data;

/**
 * The raw output of a single engine generation. `rawText` is not yet validated
 * against the extraction schema — the router runs the caller's validate callback
 * to decide success/fallback. Token counts may be null (Ollama omits
 * prompt_eval_count on a cached prompt).
 */
final readonly class GenerationResult
{
    public function __construct(
        public string $rawText,
        public string $model,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public float $costUsd = 0.0,
        public int $durationMs = 0,
    ) {}
}
