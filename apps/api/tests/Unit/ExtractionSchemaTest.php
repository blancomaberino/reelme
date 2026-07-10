<?php

use App\Support\Contracts\ExtractionSchema;
use Tests\TestCase;

// Needs the framework (config/base_path); Unit tests are otherwise framework-free.
uses(TestCase::class);

function loadExample(string $file, bool $assoc = false): object|array
{
    $path = config('contracts.examples_path')."/{$file}";
    $raw = file_get_contents($path);

    expect($raw)->not->toBeFalse("fixture missing: {$path}");

    return json_decode($raw, $assoc, flags: JSON_THROW_ON_ERROR);
}

it('resolves the canonical schema file', function () {
    // Path may be the monorepo default or an env override (container/deploy);
    // what matters is it points at the extraction schema and the file exists.
    expect(ExtractionSchema::path())->toEndWith('extraction.schema.json')
        ->and(file_exists(ExtractionSchema::path()))->toBeTrue();
});

it('validates the shared valid fixture (round-trip parity with the TS/Ajv test)', function () {
    $result = ExtractionSchema::validate(loadExample('valid-extraction.json'));

    expect($result->isValid())->toBeTrue()
        ->and(ExtractionSchema::errors($result))->toBe([]);
});

it('rejects the shared invalid fixture', function () {
    $result = ExtractionSchema::validate(loadExample('invalid-extraction.json'));

    expect($result->isValid())->toBeFalse();

    // Same violations the Ajv test asserts: missing `confidence`, an extra
    // top-level property, and an out-of-bounds frame_ref.
    $flat = json_encode(ExtractionSchema::errors($result));
    expect($flat)->toContain('confidence');
});

it('accepts an array payload by normalizing it to an object', function () {
    $payload = loadExample('valid-extraction.json', assoc: true);

    expect(ExtractionSchema::validate($payload)->isValid())->toBeTrue();
});
