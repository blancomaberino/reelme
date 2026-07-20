<?php

use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\SourcePost;

/**
 * The place picture (T-084) end-to-end: a curated place-owned image drives the
 * map marker (thumbnail → main image) and the detail payload, taking precedence
 * over the reel-derived poster.
 */
const PIC_BBOX = '-0.20,51.45,-0.05,51.55';

it('prefers the place thumbnail, then image, over the reel poster on map pins', function () {
    $withThumb = pictePlaceWithPoster(51.5117, -0.1300, 'ThumbPlace', 'https://cdn.example/reel.jpg');
    $withThumb->update(['thumbnail_url' => 'https://cdn.example/marker.jpg', 'image_url' => 'https://cdn.example/main.jpg']);

    $imageOnly = pictePlaceWithPoster(51.5100, -0.1200, 'ImagePlace', 'https://cdn.example/reel2.jpg');
    $imageOnly->update(['image_url' => 'https://cdn.example/main2.jpg']);

    $reelOnly = pictePlaceWithPoster(51.5000, -0.1000, 'ReelPlace', 'https://cdn.example/reel3.jpg');

    $pins = collect($this->getJson('/api/v1/map/places?bbox='.PIC_BBOX.'&zoom=16')->assertOk()->json('data.pins'))
        ->keyBy('name');

    expect($pins['ThumbPlace']['thumbnail_url'])->toBe('https://cdn.example/marker.jpg')
        ->and($pins['ImagePlace']['thumbnail_url'])->toBe('https://cdn.example/main2.jpg')
        ->and($pins['ReelPlace']['thumbnail_url'])->toBe('https://cdn.example/reel3.jpg');
});

it('exposes image_url and thumbnail_url on the place detail', function () {
    $place = Place::factory()->active()->atPoint(51.51, -0.13)->create([
        'image_url' => 'https://cdn.example/main.jpg',
        'thumbnail_url' => 'https://cdn.example/marker.jpg',
    ]);

    $this->getJson("/api/v1/places/{$place->slug}")
        ->assertOk()
        ->assertJsonPath('data.image_url', 'https://cdn.example/main.jpg')
        ->assertJsonPath('data.thumbnail_url', 'https://cdn.example/marker.jpg');
});

/** An active place with a primary source whose reel carries an oEmbed poster. */
function pictePlaceWithPoster(float $lat, float $lng, string $name, string $poster): Place
{
    $place = Place::factory()->active()->atPoint($lat, $lng)->create(['name' => $name]);
    $share = Share::factory()->create();
    /** @var SourcePost $post */
    $post = $share->sourcePost;
    $post->update(['oembed_json' => ['thumbnail_url' => $poster]]);
    PlaceSource::factory()->primary()->create([
        'place_id' => $place->id,
        'share_id' => $share->id,
        'source_post_id' => $share->source_post_id,
    ]);

    return $place;
}
