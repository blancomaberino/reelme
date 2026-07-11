<?php

use App\Enums\PlaceStatus;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Tag;
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

it('promotes a survivor source when the winner had no primary', function () {
    $winner = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Winner']);
    $loser = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Loser']);

    // Winner has only a non-primary source; loser owns the primary.
    PlaceSource::factory()->create(['place_id' => $winner->id, 'is_primary' => false]);
    PlaceSource::factory()->primary()->create(['place_id' => $loser->id]);

    (new PlaceMerger)->merge($winner, $loser);

    expect(PlaceSource::where('place_id', $winner->id)->where('is_primary', true)->count())->toBe(1);
});

it('follows the merge chain to the live survivor across more than one hop', function () {
    $survivor = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Survivor']);
    $mid = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Mid']);
    (new PlaceMerger)->merge($survivor, $mid); // mid → survivor

    // A later admin merge folds the survivor itself into a top place.
    $top = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Top']);
    (new PlaceMerger)->merge($top, $survivor->fresh()); // survivor → top (chain: mid → survivor → top)

    $newcomer = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Newcomer']);
    PlaceSource::factory()->create(['place_id' => $newcomer->id]);

    // Merging into `mid` (2 hops from top) must land the source on `top`, not a tombstone.
    (new PlaceMerger)->merge($mid->fresh(), $newcomer);

    expect($newcomer->fresh()->merged_into_place_id)->toBe($top->id)
        ->and(PlaceSource::where('place_id', $top->id)->count())->toBe(1);
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

it('rehomes discovery tags to the winner and strips the tombstone (T-031)', function () {
    $winner = Place::factory()->active()->atPoint(51.5, -0.13)->create();
    $loser = Place::factory()->atPoint(51.5001, -0.1301)->create();

    $shared = Tag::factory()->create(['slug' => 'ramen', 'name' => 'Ramen']);
    $unique = Tag::factory()->create(['slug' => 'late-night', 'name' => 'Late Night']);
    $winner->tags()->attach($shared->id, ['source' => 'extraction', 'confidence' => 0.5]);
    $loser->tags()->attach($shared->id, ['source' => 'extraction', 'confidence' => 0.9]);
    $loser->tags()->attach($unique->id, ['source' => 'manual', 'confidence' => null]);

    app(PlaceMerger::class)->merge($winner, $loser);

    $winnerTags = $winner->fresh()->tags()->get()->keyBy('slug');
    expect($winnerTags->keys()->all())->toEqualCanonicalizing(['ramen', 'late-night'])
        ->and((float) $winnerTags['ramen']->pivot->confidence)->toBe(0.9) // max wins
        ->and($winnerTags['late-night']->pivot->source)->toBe('manual');
    expect($loser->fresh()->tags()->count())->toBe(0);
});
