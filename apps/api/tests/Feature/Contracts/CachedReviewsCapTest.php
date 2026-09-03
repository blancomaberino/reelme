<?php

use App\Support\CachedReviews;
use App\Support\Contracts\ApiSchema;

/**
 * The cap is stated in PHP once and in `place.json` twice, and nothing in the
 * type system binds them. "Three files a human keeps in sync" is the exact
 * shape T-128 was filed to fix: `place.json` advertised "at most 5" in prose
 * while enforcing nothing, and nobody noticed until a review read the sentence.
 *
 * So the schema is read at runtime and compared. The schema is the contract of
 * record; PHP follows it. Resolved through `contracts.schemas_path`, the same
 * config {@see ApiSchema} uses, so this test cannot end
 * up reading a different copy than the app validates against.
 */
it('agrees with every maxItems the place contract states for cached reviews', function () {
    $dir = (string) config('contracts.schemas_path');
    $place = json_decode(
        (string) file_get_contents($dir.'/place.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $googleReviews = $place['properties']['google_reviews'] ?? null;
    $snippets = $place['properties']['review_sources']['items']['properties']['snippets'] ?? null;

    // Assert the SHAPE first. Without this, a reshaped schema makes both
    // comparisons below read null and the test would pass while checking
    // nothing — the failure mode this whole file exists to refuse.
    expect($googleReviews)->toBeArray()
        ->and($snippets)->toBeArray()
        ->and($googleReviews['maxItems'] ?? null)->toBe(CachedReviews::MAX)
        ->and($snippets['maxItems'] ?? null)->toBe(CachedReviews::MAX);
});
