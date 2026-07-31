<?php

use App\Support\Contracts\ApiSchema;
use Tests\TestCase;

// Needs the framework (config paths); Unit tests are otherwise framework-free.
uses(TestCase::class);

/**
 * The validator is memoized in a private static so every test pays for the
 * resolver setup once. Resetting it is the only way to exercise the
 * misconfiguration guards — and it must be restored, or the next test in the
 * process inherits a validator built from this test's config.
 */
function resetApiSchemaValidator(): void
{
    $property = new ReflectionProperty(ApiSchema::class, 'validator');
    $property->setValue(null, null);
}

afterEach(function () {
    resetApiSchemaValidator();
});

it('validates a payload against a named schema', function () {
    $payload = [
        'id' => '1',
        'username' => 'marce',
        'name' => null,
        'avatar_path' => null,
    ];

    expect(ApiSchema::errors(ApiSchema::validate($payload, 'user-summary')))->toBe([]);
});

it('reports a missing required property at the object pointer', function () {
    $errors = ApiSchema::errors(ApiSchema::validate(['id' => '1'], 'user-summary'));

    expect($errors)->toHaveKey('/')
        ->and(implode(' ', $errors['/']))->toContain('username');
});

it('reports a retyped property at its own pointer', function () {
    // Complete but for `name`, which must be string|null — so the error lands on
    // the field, not on the object.
    $errors = ApiSchema::errors(ApiSchema::validate(
        ['id' => '1', 'username' => 'marce', 'name' => 12, 'avatar_path' => null],
        'user-summary',
    ));

    expect($errors)->toHaveKey('/name')
        ->and(implode(' ', $errors['/name']))->toContain('type');
});

it('surfaces the nested cause behind a nullable $ref, not just the anyOf branch', function () {
    // share.json types `analysis.extraction` as null-or-the-extraction-contract.
    // Multi-message formatting is what makes the real cause visible; the single
    // top-level message is only ever "must match the type: null".
    $share = [
        'id' => '1',
        'status' => 'published',
        'status_history' => [],
        'source_post' => [
            'id' => '2', 'platform' => 'instagram', 'url' => 'https://example.test/p/1',
            'author_handle' => null, 'caption' => null, 'fetch_status' => 'ok',
        ],
        'analysis' => [
            'run_id' => '3',
            'model' => 'qwen2.5-vl:7b',
            'status' => 'succeeded',
            'confidence' => 0.9,
            'extraction' => ['places' => []],
        ],
        'failure' => null,
        'can_publish_best_guess' => false,
        'place' => null,
        'places' => [],
        'pending_place_count' => 0,
        'pending_places' => [],
    ];

    $errors = ApiSchema::errors(ApiSchema::validate($share, 'share'));

    expect($errors)->toHaveKey('/analysis/extraction')
        ->and(implode(' ', $errors['/analysis/extraction']))->toContain('influencer');
});

it('fails loudly when the schemas directory is missing', function () {
    resetApiSchemaValidator();
    config()->set('contracts.schemas_path', '/nonexistent/schemas');

    expect(fn () => ApiSchema::validate([], 'user-summary'))
        ->toThrow(RuntimeException::class, '/nonexistent/schemas');
});

it('fails loudly when the extraction contract is missing', function () {
    // share.json `$ref`s it across directories, so a schemas dir alone is not
    // enough — an unresolvable ref would otherwise fail as a confusing
    // validation error on every share payload.
    resetApiSchemaValidator();
    config()->set('contracts.extraction_schema_path', '/nonexistent/extraction.schema.json');

    expect(fn () => ApiSchema::validate([], 'user-summary'))
        ->toThrow(RuntimeException::class, '/nonexistent/extraction.schema.json');
});
