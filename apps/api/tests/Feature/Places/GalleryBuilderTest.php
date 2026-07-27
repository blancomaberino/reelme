<?php

use App\Models\Place;
use App\Services\Places\Enrichment\GalleryBuilder;

/**
 * The gallery merge policy (T-099): website-owned images first, then Google
 * photos whose attribution names the business, then the rest, then a reel
 * last-resort — deduped by normalized URL and capped.
 */
function buildGallery(Place $place, array $entries, ?string $reel = null, ?string $pinned = null): array
{
    return app(GalleryBuilder::class)->build($place, $entries, $reel, $pinned);
}

it('orders website first, then business-attributed Google, then the rest', function () {
    $place = Place::factory()->make(['name' => "Joe's Diner", 'website' => 'https://joes.com']);

    // Deliberately out of priority order in the input.
    $gallery = buildGallery($place, [
        ['url' => 'https://g/other.jpg', 'source' => 'google', 'attribution' => 'Some Tourist'],
        ['url' => 'https://joes.com/hero.jpg', 'source' => 'website', 'attribution' => null],
        ['url' => 'https://g/owned.jpg', 'source' => 'google', 'attribution' => "Joe's Diner"],
    ]);

    expect(array_column($gallery, 'url'))->toBe([
        'https://joes.com/hero.jpg', // owned website image
        'https://g/owned.jpg',       // Google photo attributed to the business
        'https://g/other.jpg',       // remaining Google photo
    ])->and(array_column($gallery, 'source'))->toBe(['website', 'google', 'google']);
});

it('matches business attribution by website domain, not just name', function () {
    $place = Place::factory()->make(['name' => 'The Corner', 'website' => 'https://gusteaus.com']);

    $gallery = buildGallery($place, [
        ['url' => 'https://g/a.jpg', 'source' => 'google', 'attribution' => 'Random Person'],
        ['url' => 'https://g/b.jpg', 'source' => 'google', 'attribution' => 'gusteaus'], // domain label
    ]);

    // The domain-matched photo outranks the unrelated one.
    expect(array_column($gallery, 'url'))->toBe(['https://g/b.jpg', 'https://g/a.jpg']);
});

it('dedups by normalized URL (scheme/case/trailing slash) and caps at max_images', function () {
    config(['places.enrich.gallery.max_images' => 2]);
    $place = Place::factory()->make(['name' => 'X', 'website' => 'https://x.com']);

    $gallery = buildGallery($place, [
        ['url' => 'https://cdn/a.jpg', 'source' => 'website', 'attribution' => null],
        ['url' => 'https://cdn/a.jpg/', 'source' => 'website', 'attribution' => null], // trailing slash dup
        ['url' => 'HTTP://CDN/a.jpg', 'source' => 'website', 'attribution' => null],    // scheme/case dup
        ['url' => 'https://cdn/b.jpg', 'source' => 'website', 'attribution' => null],
        ['url' => 'https://cdn/c.jpg', 'source' => 'website', 'attribution' => null],   // over the cap
    ]);

    expect(array_column($gallery, 'url'))->toBe(['https://cdn/a.jpg', 'https://cdn/b.jpg']);
});

it('appends the reel thumbnail as a last-resort so a crawl-less place keeps one', function () {
    $place = Place::factory()->make(['name' => 'X', 'website' => null]);

    $gallery = buildGallery($place, [], 'https://cdn/reel-thumb.jpg');

    expect($gallery)->toBe([
        ['url' => 'https://cdn/reel-thumb.jpg', 'source' => 'reel', 'attribution' => null],
    ]);
});

it('ranks the reel thumbnail below real business photos', function () {
    $place = Place::factory()->make(['name' => 'X', 'website' => 'https://x.com']);

    $gallery = buildGallery($place, [
        ['url' => 'https://x.com/real.jpg', 'source' => 'website', 'attribution' => null],
    ], 'https://cdn/reel-thumb.jpg');

    expect(array_column($gallery, 'source'))->toBe(['website', 'reel']);
});

it('drops non-http entries and returns an empty gallery when nothing is usable', function () {
    $place = Place::factory()->make(['name' => 'X', 'website' => null]);

    expect(buildGallery($place, [
        ['url' => 'ftp://cdn/x.jpg', 'source' => 'website', 'attribution' => null],
        ['url' => '', 'source' => 'google', 'attribution' => null],
    ]))->toBe([]);
});

it('matches business attribution for a hyphenated website domain', function () {
    $place = Place::factory()->make(['name' => 'The Corner', 'website' => 'https://my-cafe.com']);

    $gallery = buildGallery($place, [
        ['url' => 'https://g/a.jpg', 'source' => 'google', 'attribution' => 'Random Person'],
        ['url' => 'https://g/b.jpg', 'source' => 'google', 'attribution' => 'My Cafe'], // folds to the domain label
    ]);

    expect(array_column($gallery, 'url'))->toBe(['https://g/b.jpg', 'https://g/a.jpg']);
});

it('pins a locked hero as gallery[0] ahead of every source tier', function () {
    $place = Place::factory()->make(['name' => 'X', 'website' => 'https://x.com']);

    $gallery = buildGallery(
        $place,
        [
            ['url' => 'https://x.com/a.jpg', 'source' => 'website', 'attribution' => null],
            ['url' => 'https://g/b.jpg', 'source' => 'google', 'attribution' => null],
        ],
        null,
        'https://manual/hero.jpg', // locked hero, not emitted by any source
    );

    expect(array_column($gallery, 'url'))->toBe([
        'https://manual/hero.jpg', // pinned first
        'https://x.com/a.jpg',
        'https://g/b.jpg',
    ]);
});

it('does not duplicate a pinned hero already present among the entries', function () {
    $place = Place::factory()->make(['name' => 'X', 'website' => 'https://x.com']);

    $gallery = buildGallery(
        $place,
        [
            ['url' => 'https://g/b.jpg', 'source' => 'google', 'attribution' => null],
            ['url' => 'https://x.com/hero.jpg', 'source' => 'website', 'attribution' => null],
        ],
        null,
        'https://x.com/hero.jpg', // same as a website entry → deduped, still first
    );

    expect(array_column($gallery, 'url'))->toBe(['https://x.com/hero.jpg', 'https://g/b.jpg']);
});

it('does not collapse two case-different paths on the same host', function () {
    $place = Place::factory()->make(['name' => 'X', 'website' => null]);

    $gallery = buildGallery($place, [
        ['url' => 'https://cdn.example/Photo.jpg', 'source' => 'website', 'attribution' => null],
        ['url' => 'https://cdn.example/photo.jpg', 'source' => 'website', 'attribution' => null],
    ]);

    // Host case is ignored for dedup, but the path (case-sensitive on a CDN) is not.
    expect($gallery)->toHaveCount(2);
});
