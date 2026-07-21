<?php

use App\Models\Place;
use App\Services\Places\PlaceDedupMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * PlaceDedupMatcher (T-095): the geo + name dedup scan, isolated. Verifies the
 * radius + similarity gates and the max(trigram, Jaro-Winkler) signal.
 */
function matcher(): PlaceDedupMatcher
{
    return app(PlaceDedupMatcher::class);
}

it('matches a near place with a similar name, and excludes far or dissimilar ones', function () {
    // Same name, ~0m away → a match.
    $same = Place::factory()->active()->atPoint(51.5000, -0.1300)->create(['name' => 'Lanzhou Beef Noodle']);
    // Same name but ~2km away (outside the 75m dedup radius) → not a match.
    Place::factory()->active()->atPoint(51.5180, -0.1300)->create(['name' => 'Lanzhou Beef Noodle']);
    // Very close but an unrelated name → below the similarity threshold.
    Place::factory()->active()->atPoint(51.5000, -0.1301)->create(['name' => 'Pizza Palace']);

    $matches = matcher()->fuzzyMatches(51.5000, -0.1300, 'Lanzhou Beef Noodle');

    expect(collect($matches)->pluck('place_id')->all())->toBe([$same->id]);
});

it('does not match a removed tombstone (revival is google_place_id-only)', function () {
    Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Ghost Diner', 'status' => 'removed']);

    expect(matcher()->fuzzyMatches(51.5, -0.13, 'Ghost Diner'))->toBe([]);
});

it('candidatesFor excludes the place itself and sorts best-match first', function () {
    $subject = Place::factory()->active()->atPoint(51.5000, -0.1300)->create(['name' => 'Taco House']);
    $exact = Place::factory()->active()->atPoint(51.5000, -0.1300)->create(['name' => 'Taco House']);
    $close = Place::factory()->active()->atPoint(51.5001, -0.1300)->create(['name' => 'Taco Housee']);

    $candidates = matcher()->candidatesFor($subject->refresh());
    $ids = collect($candidates)->pluck('place_id');

    expect($ids)->not->toContain($subject->id)          // never itself
        ->and($ids)->toContain($exact->id)->toContain($close->id)
        ->and($candidates[0]['similarity'])->toBeGreaterThanOrEqual($candidates[1]['similarity']); // best-first
});
