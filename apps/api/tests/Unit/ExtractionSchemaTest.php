<?php

use App\Support\Contracts\ExtractionSchema;
use Tests\TestCase;

// Needs the framework (config/base_path); Unit tests are otherwise framework-free.
uses(TestCase::class);

function loadExample(string $file): object
{
    $path = config('contracts.examples_path')."/{$file}";
    $raw = file_get_contents($path);

    expect($raw)->not->toBeFalse("fixture missing: {$path}");

    return json_decode($raw, false, flags: JSON_THROW_ON_ERROR);
}

it('resolves the canonical schema file', function () {
    expect(ExtractionSchema::path())->toEndWith('packages/contracts/extraction.schema.json')
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
    $payload = json_decode(
        file_get_contents(config('contracts.examples_path').'/valid-extraction.json'),
        true,
    );

    expect(ExtractionSchema::validate($payload)->isValid())->toBeTrue();
});
