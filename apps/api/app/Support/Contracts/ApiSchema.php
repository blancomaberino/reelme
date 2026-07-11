<?php

namespace App\Support\Contracts;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * Validates API response payloads against the canonical schemas in
 * `packages/contracts/schemas/*.json` (T-030) — the same files the TS types
 * are generated from. Used by contract tests to pin resource output shapes.
 *
 * Schemas reference each other by relative `$ref` against their absolute
 * `$id`s, so the whole directory is registered under the shared id prefix.
 */
final class ApiSchema
{
    private const ID_PREFIX = 'https://contracts.reelmap.app/schemas/';

    private static ?Validator $validator = null;

    /**
     * Validate a decoded payload against `<name>.json` (e.g. `place-summary`).
     *
     * @param  object|array<mixed>  $payload
     */
    public static function validate(object|array $payload, string $name): ValidationResult
    {
        return self::validator()->validate(self::normalize($payload), self::ID_PREFIX."{$name}.json");
    }

    /**
     * Flatten a validation result into `dotted.path => [messages]`.
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
            $dir = (string) config('contracts.schemas_path');
            if (! is_dir($dir)) {
                throw new RuntimeException("Contract schemas directory not found at [{$dir}].");
            }

            $validator = new Validator;
            $validator->parser()->setOption('defaultDraft', '07');
            $validator->resolver()?->registerPrefix(self::ID_PREFIX, $dir);
            self::$validator = $validator;
        }

        return self::$validator;
    }

    /**
     * @param  object|array<mixed>  $payload
     */
    private static function normalize(object|array $payload): mixed
    {
        return json_decode((string) json_encode($payload, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);
    }
}
