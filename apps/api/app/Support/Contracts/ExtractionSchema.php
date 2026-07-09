<?php

namespace App\Support\Contracts;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * Validates AI extraction payloads against the canonical
 * `packages/contracts/extraction.schema.json` (the single source of truth shared
 * with the mobile app). Used by the analysis pipeline (T-021) to gate
 * `analysis_runs.result_json`.
 */
final class ExtractionSchema
{
    /**
     * Absolute path to the canonical extraction schema file.
     */
    public static function path(): string
    {
        return (string) config('contracts.extraction_schema_path');
    }

    /**
     * Validate a decoded payload (stdClass / array) against the schema.
     *
     * Arrays are normalized to objects so JSON object vs list semantics match
     * the schema. Formats (e.g. `uri`) are validated for parity with the Ajv
     * side of the round-trip.
     *
     * @param  object|array<mixed>  $payload
     */
    public static function validate(object|array $payload): ValidationResult
    {
        $data = self::normalize($payload);

        $validator = new Validator;
        $validator->parser()->setOption('defaultDraft', '07');

        return $validator->validate($data, self::schema());
    }

    /**
     * Flatten a validation result into `dotted.path => [messages]` for surfacing
     * to clients / the review UI. Empty when the payload is valid.
     *
     * @return array<string, array<int, string>>
     */
    public static function errors(ValidationResult $result): array
    {
        $error = $result->error();

        if ($error === null) {
            return [];
        }

        return (new ErrorFormatter)->format($error, false);
    }

    private static function schema(): object
    {
        $path = self::path();
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException("Extraction schema not found at [{$path}].");
        }

        return json_decode($raw, false, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  object|array<mixed>  $payload
     */
    private static function normalize(object|array $payload): mixed
    {
        return json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);
    }
}
