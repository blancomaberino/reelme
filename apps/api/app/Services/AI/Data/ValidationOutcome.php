<?php

namespace App\Services\AI\Data;

/**
 * The result of the caller's per-attempt validation of an engine's raw output.
 * The router owns engine selection, not schema knowledge — T-021 supplies a
 * `validate: fn(string $raw): ValidationOutcome` callback (its repair loop lives
 * there). A valid outcome carries the parsed payload and overall confidence so
 * the router can persist `result_json` + gate on `confidence.overall`.
 */
final readonly class ValidationOutcome
{
    /**
     * @param  array<string, mixed>|null  $data  parsed, schema-valid payload (null when invalid)
     */
    private function __construct(
        public bool $valid,
        public ?array $data = null,
        public ?float $confidence = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function valid(array $data, ?float $confidence): self
    {
        return new self(valid: true, data: $data, confidence: $confidence);
    }

    public static function invalid(): self
    {
        return new self(valid: false);
    }
}
