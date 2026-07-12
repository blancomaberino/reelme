<?php

use App\Models\Influencer;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use App\Support\Contracts\ApiSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Model::preventLazyLoading();
});

afterEach(function () {
    Model::preventLazyLoading(false);
});

/**
 * Contract tests (T-030): live endpoint output must validate against the
 * canonical JSON Schemas in packages/contracts/schemas — the same files the
 * mobile app's TS types are generated from.
 */
function contractPlace(): Place
{
    $place = Place::factory()->active()->atPoint(38.7169, -9.1355)->create([
        'cuisine_primary' => 'chinese',
        'price_range' => 2,
        'google_rating' => 4.5,
        'google_rating_count' => 120,
        'shares_count' => 1,
    ]);

    $influencer = Influencer::factory()->create();
    $post = SourcePost::factory()->create(['influencer_id' => $influencer->id, 'posted_at' => now()]);
    $share = Share::factory()->create([
        'source_post_id' => $post->id,
        'user_id' => User::factory()->create(['is_public' => true])->id,
    ]);
    PlaceSource::factory()->create([
        'place_id' => $place->id,
        'source_post_id' => $post->id,
        'share_id' => $share->id,
        'extraction_snapshot_json' => [
            'cuisines' => ['chinese'],
            'dishes' => [['name' => 'Noodles', 'shown_in_video' => true]],
        ],
        'is_primary' => true,
    ]);

    return $place;
}

it('index rows validate against place-summary.json', function () {
    contractPlace();

    $rows = $this->getJson('/api/v1/places?near=38.7169,-9.1355')->assertOk()->json('data');
    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        $result = ApiSchema::validate($row, 'place-summary');
        expect(ApiSchema::errors($result))->toBe([]);
    }
});

it('place detail validates against place.json (with includes)', function () {
    $place = contractPlace();

    $data = $this->getJson("/api/v1/places/{$place->slug}?include=sources,offers")->assertOk()->json('data');

    $result = ApiSchema::validate($data, 'place');
    expect(ApiSchema::errors($result))->toBe([]);
});

it('sources rows validate against place-source.json', function () {
    $place = contractPlace();

    $rows = $this->getJson("/api/v1/places/{$place->slug}/sources")->assertOk()->json('data');
    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        $result = ApiSchema::validate($row, 'place-source');
        expect(ApiSchema::errors($result))->toBe([]);
    }
});
