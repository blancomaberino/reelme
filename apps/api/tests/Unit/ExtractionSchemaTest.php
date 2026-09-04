<?php

use App\Models\Dish;
use App\Services\Places\DishMaterializer;
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

it('pins the dish write-path caps to the extraction contract', function () {
    // Five hand-copied mirrors of the same three numbers: the contract, the two
    // `Dish` constants, the materializer's per-source cap, and the migration's
    // column widths. Raise the contract alone and the write path starts silently
    // truncating with every gate green — so the mirror is asserted rather than
    // maintained by discipline (CLAUDE.md: make the invariant enforced).
    //
    // Literals on BOTH sides deliberately: two mirrors moving together to a
    // wrong number is still drift.
    // Through the config, not a hardcoded relative path: the Sail container
    // mounts only apps/api, and `CONTRACTS_EXTRACTION_SCHEMA_PATH` is what points
    // at the real file there — the same resolution ExtractionSchema itself uses.
    $dish = json_decode(
        (string) file_get_contents((string) config('contracts.extraction_schema_path')),
        true,
    )['properties']['places']['items']['properties']['dishes'];

    expect($dish['maxItems'])->toBe(32)
        ->and($dish['items']['properties']['name']['maxLength'])->toBe(120)
        ->and($dish['items']['properties']['price']['maxLength'])->toBe(40);

    expect(Dish::MAX_NAME)->toBe($dish['items']['properties']['name']['maxLength'])
        ->and(Dish::MAX_PRICE)->toBe($dish['items']['properties']['price']['maxLength'])
        // The per-source cap the comment above claims is mirrored — it was named
        // and then not asserted, so changing it to 16 failed nothing.
        ->and(DishMaterializer::MAX_DISHES_PER_SOURCE)->toBe($dish['maxItems']);

});
