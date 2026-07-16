<?php

use App\Enums\PlaceStatus;
use App\Models\AnalysisRun;
use App\Models\HiddenPlace;
use App\Models\Place;
use App\Models\PlaceList;
use App\Models\PlaceMerge;
use App\Models\PlaceSource;
use App\Models\Tag;
use App\Models\User;
use App\Services\Places\PlaceMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('rehomes saved-list items + hides onto the survivor so a saved place follows a merge (BUG B)', function () {
    $winner = Place::factory()->active()->atPoint(51.5, -0.13)->create(['name' => 'Winner']);
    $loser = Place::factory()->active()->atPoint(51.5, -0.13)->create(['name' => 'Loser']);
    PlaceSource::factory()->primary()->create(['place_id' => $winner->id]);
    PlaceSource::factory()->primary()->create(['place_id' => $loser->id]);

    $saver = User::factory()->create();
    $list = PlaceList::factory()->for($saver)->create();
    $list->items()->create(['place_id' => $loser->id, 'position' => 1]);
    $hider = User::factory()->create();
    HiddenPlace::create(['user_id' => $hider->id, 'place_id' => $loser->id]);

    (new PlaceMerger)->merge($winner, $loser);

    // Save + hide follow the merge to the survivor; nothing dangles on the tombstone.
    $this->assertDatabaseHas('place_list_items', ['place_list_id' => $list->id, 'place_id' => $winner->id]);
    $this->assertDatabaseMissing('place_list_items', ['place_id' => $loser->id]);
    $this->assertDatabaseHas('hidden_places', ['user_id' => $hider->id, 'place_id' => $winner->id]);
    $this->assertDatabaseMissing('hidden_places', ['place_id' => $loser->id]);

    // The saver's collection now shows the survivor (previously the save vanished).
    expect(Place::query()->publiclyVisible()->mine($saver)->pluck('name'))->toContain('Winner');
});

it('moves a saved place across a merge even when the survivor is saved in a DIFFERENT list', function () {
    $winner = Place::factory()->active()->atPoint(51.5, -0.13)->create();
    $loser = Place::factory()->active()->atPoint(51.5, -0.13)->create();
    PlaceSource::factory()->primary()->create(['place_id' => $winner->id]);
    PlaceSource::factory()->primary()->create(['place_id' => $loser->id]);

    $user = User::factory()->create();
    $listX = PlaceList::factory()->for($user)->create();
    $listY = PlaceList::factory()->for($user)->create();
    $listX->items()->create(['place_id' => $loser->id, 'position' => 1]);   // loser in X
    $listY->items()->create(['place_id' => $winner->id, 'position' => 1]);  // winner in Y

    (new PlaceMerger)->merge($winner, $loser);

    // The loser's X-membership follows to the winner (X had no winner); Y keeps
    // its own. The survivor ends up saved in BOTH lists — no false unique clash.
    $this->assertDatabaseHas('place_list_items', ['place_list_id' => $listX->id, 'place_id' => $winner->id]);
    $this->assertDatabaseHas('place_list_items', ['place_list_id' => $listY->id, 'place_id' => $winner->id]);
    $this->assertDatabaseMissing('place_list_items', ['place_id' => $loser->id]);
});

it('dedupes saved-list items + hides when a user already had the survivor (no unique violation)', function () {
    $winner = Place::factory()->active()->atPoint(51.5, -0.13)->create();
    $loser = Place::factory()->active()->atPoint(51.5, -0.13)->create();
    PlaceSource::factory()->primary()->create(['place_id' => $winner->id]);
    PlaceSource::factory()->primary()->create(['place_id' => $loser->id]);

    $user = User::factory()->create();
    $list = PlaceList::factory()->for($user)->create();
    $list->items()->create(['place_id' => $winner->id, 'position' => 1]);
    $list->items()->create(['place_id' => $loser->id, 'position' => 2]); // both in one list
    HiddenPlace::create(['user_id' => $user->id, 'place_id' => $winner->id]);
    HiddenPlace::create(['user_id' => $user->id, 'place_id' => $loser->id]);

    (new PlaceMerger)->merge($winner, $loser);

    // Collapses to one row each on the survivor — the loser's redundant rows dropped.
    expect(PlaceList::find($list->id)->items()->where('place_id', $winner->id)->count())->toBe(1)
        ->and(HiddenPlace::where('user_id', $user->id)->where('place_id', $winner->id)->count())->toBe(1);
    $this->assertDatabaseMissing('place_list_items', ['place_id' => $loser->id]);
});

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
        ->and($loser->shares_count)->toBe(0) // tombstone counters zeroed, not stale
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

// --- T-035: audit trail + unmerge ---

it('writes a place_merges audit row with the acting admin and snapshots', function () {
    $admin = User::factory()->admin()->create();
    $winner = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Winner', 'phone' => null]);
    $loser = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Loser', 'phone' => '+441234567890']);
    $source = PlaceSource::factory()->primary()->create(['place_id' => $loser->id]);

    (new PlaceMerger)->merge($winner, $loser, $admin);

    $merge = PlaceMerge::sole();
    expect($merge->source_place_id)->toBe($loser->id)
        ->and($merge->target_place_id)->toBe($winner->id)
        ->and($merge->performed_by_user_id)->toBe($admin->id)
        ->and($merge->rehomed_place_source_ids)->toBe([$source->id])
        ->and($merge->dropped_duplicate_place_sources)->toBe([])
        ->and($merge->target_backfilled_fields)->toHaveKey('phone', '+441234567890')
        ->and($merge->source_snapshot['attributes']['status'])->toBe('pending')
        ->and($merge->source_snapshot['source_primary_flags'][(string) $source->id])->toBeTrue()
        ->and($merge->undone_at)->toBeNull();
});

it('does not write an audit row for a no-op merge (self / already merged)', function () {
    $winner = Place::factory()->atPoint(51.5, -0.13)->create();
    (new PlaceMerger)->merge($winner, $winner);

    expect(PlaceMerge::count())->toBe(0);
});

it('recomputes avg_extraction_confidence from the merged source set', function () {
    $winner = Place::factory()->atPoint(51.5, -0.13)->create();
    $loser = Place::factory()->atPoint(51.5, -0.13)->create();

    $runA = AnalysisRun::factory()->succeeded()->create(['overall_confidence' => 0.6]);
    $runB = AnalysisRun::factory()->succeeded()->create(['overall_confidence' => 0.9]);
    PlaceSource::factory()->primary()->create(['place_id' => $winner->id, 'analysis_run_id' => $runA->id]);
    PlaceSource::factory()->primary()->create(['place_id' => $loser->id, 'analysis_run_id' => $runB->id]);

    (new PlaceMerger)->merge($winner, $loser);

    expect((float) $winner->fresh()->avg_extraction_confidence)->toBe(0.75);
});

it('unmerges: sources, primary flags, attributes, counters and status all restored', function () {
    $winner = Place::factory()->active()->atPoint(51.5, -0.13)
        ->create(['name' => 'Winner', 'google_place_id' => null, 'phone' => null]);
    $loser = Place::factory()->atPoint(51.5, -0.13)->withGooglePlaceId('ChIJloser')
        ->create(['name' => 'Loser', 'phone' => '+441234567890']);

    $winnerSource = PlaceSource::factory()->primary()->create(['place_id' => $winner->id]);
    $loserSource = PlaceSource::factory()->primary()->create(['place_id' => $loser->id]);

    (new PlaceMerger)->merge($winner, $loser);
    $merge = PlaceMerge::sole();

    $restored = (new PlaceMerger)->unmerge($merge);

    $winner->refresh();
    expect($restored->id)->toBe($loser->id)
        ->and($restored->status)->toBe(PlaceStatus::Pending)
        ->and($restored->merged_into_place_id)->toBeNull()
        ->and($restored->google_place_id)->toBe('ChIJloser')
        ->and($restored->shares_count)->toBe(1)
        // The loser's source came home with its primary flag.
        ->and(PlaceSource::where('place_id', $loser->id)->pluck('id')->all())->toBe([$loserSource->id])
        ->and($loserSource->fresh()->is_primary)->toBeTrue()
        // The winner rolled back: backfills nulled, own source untouched.
        ->and($winner->google_place_id)->toBeNull()
        ->and($winner->phone)->toBeNull()
        ->and($winner->shares_count)->toBe(1)
        ->and(PlaceSource::where('place_id', $winner->id)->pluck('id')->all())->toBe([$winnerSource->id])
        ->and($merge->fresh()->undone_at)->not->toBeNull();
});

it('unmerge keeps a winner backfill the admin has since changed', function () {
    $winner = Place::factory()->atPoint(51.5, -0.13)->create(['phone' => null]);
    $loser = Place::factory()->atPoint(51.5, -0.13)->create(['phone' => '+441111111111']);

    (new PlaceMerger)->merge($winner, $loser);
    $winner->refresh();
    $winner->phone = '+449999999999'; // admin corrected it after the merge
    $winner->save();

    (new PlaceMerger)->unmerge(PlaceMerge::sole());

    expect($winner->fresh()->phone)->toBe('+449999999999');
});

it('unmerge restores tag pivots on both sides without losing post-merge gains', function () {
    $winner = Place::factory()->active()->atPoint(51.5, -0.13)->create();
    $loser = Place::factory()->atPoint(51.5001, -0.1301)->create();

    $shared = Tag::factory()->create(['slug' => 'ramen', 'name' => 'Ramen']);
    $donated = Tag::factory()->create(['slug' => 'late-night', 'name' => 'Late Night']);
    $later = Tag::factory()->create(['slug' => 'cozy', 'name' => 'Cozy']);
    $winner->tags()->attach($shared->id, ['source' => 'extraction', 'confidence' => 0.5]);
    $loser->tags()->attach($shared->id, ['source' => 'extraction', 'confidence' => 0.9]);
    $loser->tags()->attach($donated->id, ['source' => 'manual', 'confidence' => null]);

    (new PlaceMerger)->merge($winner, $loser);

    // A publish after the merge adds a brand-new tag to the winner.
    $winner->tags()->attach($later->id, ['source' => 'extraction', 'confidence' => 0.7]);

    (new PlaceMerger)->unmerge(PlaceMerge::sole());

    $winnerTags = $winner->fresh()->tags()->get()->keyBy('slug');
    expect($winnerTags->keys()->all())->toEqualCanonicalizing(['ramen', 'cozy'])
        ->and((float) $winnerTags['ramen']->pivot->confidence)->toBe(0.5) // rolled back to pre-merge
        ->and((float) $winnerTags['cozy']->pivot->confidence)->toBe(0.7); // post-merge gain kept

    $loserTags = $loser->fresh()->tags()->get()->keyBy('slug');
    expect($loserTags->keys()->all())->toEqualCanonicalizing(['ramen', 'late-night'])
        ->and((float) $loserTags['ramen']->pivot->confidence)->toBe(0.9);
});

it('refuses to unmerge twice', function () {
    $winner = Place::factory()->atPoint(51.5, -0.13)->create();
    $loser = Place::factory()->atPoint(51.5, -0.13)->create();
    (new PlaceMerger)->merge($winner, $loser);

    $merge = PlaceMerge::sole();
    (new PlaceMerger)->unmerge($merge);

    expect(fn () => (new PlaceMerger)->unmerge($merge->fresh()))
        ->toThrow(RuntimeException::class, 'already been undone');
});

it('refuses to unmerge when the survivor was itself merged away', function () {
    $winner = Place::factory()->atPoint(51.5, -0.13)->create();
    $loser = Place::factory()->atPoint(51.5, -0.13)->create();
    (new PlaceMerger)->merge($winner, $loser);
    $firstMerge = PlaceMerge::sole();

    $top = Place::factory()->atPoint(51.5, -0.13)->create();
    (new PlaceMerger)->merge($top, $winner->fresh()); // survivor tombstoned

    expect(fn () => (new PlaceMerger)->unmerge($firstMerge))
        ->toThrow(RuntimeException::class);
});

it('covers the M2 exit scenario: two shares of one restaurant end up on one place, and survive an undo', function () {
    // Two near-identical pins created by two different shares/posts.
    $a = Place::factory()->atPoint(35.6595, 139.7005)->create(['name' => 'Lanzhou Noodle House']);
    $b = Place::factory()->atPoint(35.6596, 139.7006)->create(['name' => 'Lanzhou Noodles']);
    $sourceA = PlaceSource::factory()->primary()->create(['place_id' => $a->id]);
    $sourceB = PlaceSource::factory()->primary()->create(['place_id' => $b->id]);

    (new PlaceMerger)->merge($a, $b);

    // Both shares now resolve (via their place_source) to the same survivor.
    expect($sourceA->fresh()->place_id)->toBe($a->id)
        ->and($sourceB->fresh()->place_id)->toBe($a->id)
        ->and($a->fresh()->shares_count)->toBe(2)
        ->and($b->fresh()->status)->toBe(PlaceStatus::Merged);

    // And the wrong-merge escape hatch works.
    (new PlaceMerger)->unmerge(PlaceMerge::sole());
    expect($sourceB->fresh()->place_id)->toBe($b->id)
        ->and($b->fresh()->status)->toBe(PlaceStatus::Pending);
});

it('merging the same pair twice writes a single audit row and keeps the tombstone consistent', function () {
    $winner = Place::factory()->atPoint(51.5, -0.13)->create();
    $loser = Place::factory()->atPoint(51.5, -0.13)->create(['phone' => '+441234567890']);
    PlaceSource::factory()->primary()->create(['place_id' => $loser->id]);

    (new PlaceMerger)->merge($winner, $loser);
    // A double-submit / second admin re-merging the (now stale) pair: the
    // in-transaction re-check must no-op instead of writing a second audit row
    // with an empty source set that would corrupt a later unmerge.
    (new PlaceMerger)->merge($winner->fresh(), $loser);

    expect(PlaceMerge::count())->toBe(1)
        ->and($loser->fresh()->status)->toBe(PlaceStatus::Merged);

    // And the single audit row still unmerges cleanly.
    (new PlaceMerger)->unmerge(PlaceMerge::sole());
    expect($loser->fresh()->shares_count)->toBe(1);
});

it('unmerge re-creates a survivor tag pivot that was deleted after the merge', function () {
    $winner = Place::factory()->active()->atPoint(51.5, -0.13)->create();
    $loser = Place::factory()->atPoint(51.5001, -0.1301)->create();

    $tag = Tag::factory()->create(['slug' => 'ramen', 'name' => 'Ramen']);
    $winner->tags()->attach($tag->id, ['source' => 'extraction', 'confidence' => 0.5]);

    (new PlaceMerger)->merge($winner, $loser);
    // Some cleanup deletes the winner's pivot between merge and unmerge.
    DB::table('place_tag')->where('place_id', $winner->id)->delete();

    (new PlaceMerger)->unmerge(PlaceMerge::sole());

    $restored = $winner->fresh()->tags()->get()->keyBy('slug');
    expect($restored->keys()->all())->toBe(['ramen'])
        ->and((float) $restored['ramen']->pivot->confidence)->toBe(0.5);
});

it('unmerge keeps a numeric-string backfill the admin changed to a ==-equal but distinct value', function () {
    $winner = Place::factory()->atPoint(51.5, -0.13)->create(['postal_code' => null]);
    $loser = Place::factory()->atPoint(51.5, -0.13)->create(['postal_code' => '01234']);

    (new PlaceMerger)->merge($winner, $loser);
    $winner->refresh();
    $winner->postal_code = '1234'; // '01234' == '1234' loosely, but this is an admin edit
    $winner->save();

    (new PlaceMerger)->unmerge(PlaceMerge::sole());

    expect($winner->fresh()->postal_code)->toBe('1234');
});
