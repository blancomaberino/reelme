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
 *
 * The schema and validator are memoized per process — the pipeline validates
 * many payloads and the schema never changes at runtime.
 */
final class ExtractionSchema
{
    private static ?object $schema = null;

    private static ?Validator $validator = null;

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
     * Formats (e.g. `uri`) are validated for parity with the Ajv side of the
     * round-trip.
     *
     * @param  object|array<mixed>  $payload
     */
    public static function validate(object|array $payload): ValidationResult
    {
        return self::validator()->validate(self::normalize($payload), self::schema());
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

    private static function validator(): Validator
    {
        if (self::$validator === null) {
            $validator = new Validator;
            $validator->parser()->setOption('defaultDraft', '07');
            self::$validator = $validator;
        }

        return self::$validator;
    }

    private static function schema(): object
    {
        if (self::$schema === null) {
            $path = self::path();
            $raw = @file_get_contents($path);

            if ($raw === false) {
                throw new RuntimeException("Extraction schema not found at [{$path}].");
            }

            self::$schema = json_decode($raw, false, flags: JSON_THROW_ON_ERROR);
        }

        return self::$schema;
    }

    /**
     * Arrays (e.g. from `json_decode(..., true)`) are converted to the stdClass
     * tree opis expects; an already-decoded object tree is passed through as-is.
     *
     * @param  object|array<mixed>  $payload
     */
    private static function normalize(object|array $payload): mixed
    {
        return is_array($payload)
            ? json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR)
            : $payload;
    }
}
