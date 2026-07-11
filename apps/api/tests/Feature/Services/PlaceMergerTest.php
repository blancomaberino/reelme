<?php

use App\Enums\PlaceStatus;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Services\Places\PlaceMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rehomes sources, tombstones the loser, and recounts the winner', function () {
    $winner = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Winner', 'city' => 'London']);
    $loser = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Loser', 'city' => 'London']);

    PlaceSource::factory()->primary()->create(['place_id' => $winner->id]);
    PlaceSource::factory()->primary()->create(['place_id' => $loser->id]);

    (new PlaceMerger)->merge($winner, $loser);

    $loser->refresh();
    $winner->refresh();

    expect($loser->status)->toBe(PlaceStatus::Merged)
        ->and($loser->merged_into_place_id)->toBe($winner->id)
        ->and(PlaceSource::where('place_id', $winner->id)->count())->toBe(2)
        ->and(PlaceSource::where('place_id', $loser->id)->count())->toBe(0)
        ->and($winner->shares_count)->toBe(2)
        // Exactly one primary survived the merge (partial-unique held).
        ->and(PlaceSource::where('place_id', $winner->id)->where('is_primary', true)->count())->toBe(1);
});

it('backfills the winner’s null fields from the loser', function () {
    $winner = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Winner', 'google_place_id' => null, 'phone' => null]);
    $loser = Place::factory()->atPoint(51.5, -0.13)->withGooglePlaceId('ChIJloser')->create(['name' => 'Loser', 'phone' => '+441234567890']);

    (new PlaceMerger)->merge($winner, $loser);

    $winner->refresh();
    expect($winner->google_place_id)->toBe('ChIJloser')
        ->and($winner->phone)->toBe('+441234567890');
});

it('follows the single-hop rule — merging into an already-merged place targets the survivor', function () {
    $survivor = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Survivor']);
    $middle = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Middle']);
    (new PlaceMerger)->merge($survivor, $middle); // middle → survivor

    $newcomer = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Newcomer']);
    PlaceSource::factory()->create(['place_id' => $newcomer->id]);

    (new PlaceMerger)->merge($middle->fresh(), $newcomer); // target is merged → follow to survivor

    expect($newcomer->fresh()->merged_into_place_id)->toBe($survivor->id)
        ->and(PlaceSource::where('place_id', $survivor->id)->count())->toBe(1);
});
