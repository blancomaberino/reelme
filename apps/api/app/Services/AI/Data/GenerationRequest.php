<?php

namespace App\Services\AI\Data;

/**
 * An engine-agnostic LLM request. The router hands the same request to the local
 * and remote engines; each maps it onto its own wire format. `jsonSchema` is the
 * contracts extraction schema (structured-output enforcement, 04 §5).
 */
final readonly class GenerationRequest
{
    /**
     * @param  list<GenerationPart>  $userParts
     * @param  array<string, mixed>|null  $jsonSchema
     * @param  string|null  $promptVersion  system-prompt version, recorded on each analysis_runs row
     */
    public function __construct(
        public string $systemPrompt,
        public array $userParts,
        public ?array $jsonSchema = null,
        public float $temperature = 0.0,
        public ?string $promptVersion = null,
    ) {}

    public function hasImages(): bool
    {
        foreach ($this->userParts as $part) {
            if ($part->isImage()) {
                return true;
            }
        }

        return false;
    }
}
