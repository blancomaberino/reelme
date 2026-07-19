<?php

use App\Enums\PlaceStatus;
use App\Enums\TagKind;
use App\Filament\Resources\Places\Pages\ListPlaces;
use App\Filament\Resources\Places\Pages\ViewPlace;
use App\Models\Place;
use App\Models\PlaceMerge;
use App\Models\PlaceSource;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsAdmin(): User
{
    $admin = User::factory()->admin()->create();
    test()->actingAs($admin);

    return $admin;
}

// --- Access control ---

it('blocks non-admins from the Places resource', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin/places')->assertForbidden();
});

it('blocks non-admins from Shares and AnalysisRuns resources', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin/shares')->assertForbidden();
    $this->get('/admin/analysis-runs')->assertForbidden();
});

// --- Review queue ---

it('lists all statuses by default, with the review queue as an opt-in filter', function () {
    actingAsAdmin();

    $pending = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Pending Pin']);
    $active = Place::factory()->active()->atPoint(51.5, -0.13)->create(['name' => 'Active Pin']);

    Livewire::test(ListPlaces::class)
        ->assertCanSeeTableRecords([$pending, $active]) // no forced pending-only default
        ->filterTable('review_queue', true)             // opt into the queue
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$active]);
});

// --- View page: candidates panel ---

it('renders nearby candidates with similarity and distance on the view page', function () {
    actingAsAdmin();

    $place = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Lanzhou Noodle House']);
    Place::factory()->atPoint(51.5002, -0.1301)->create(['name' => 'Lanzhou Noodles']);
    // Same name but far away — must NOT appear.
    Place::factory()->atPoint(48.85, 2.35)->create(['name' => 'Lanzhou Noodle House']);

    Livewire::test(ViewPlace::class, ['record' => $place->getKey()])
        ->assertSee('Lanzhou Noodles')
        ->assertSee('Possible duplicates')
        ->assertDontSee('48.85');
});

// --- Discovery tags on the view page ---

it('shows the place\'s materialized discovery tags on the view page', function () {
    actingAsAdmin();

    $place = Place::factory()->atPoint(51.5, -0.13)->create();
    $cuisine = Tag::create(['kind' => TagKind::Cuisine, 'name' => 'ramen', 'slug' => 'ramen']);
    $vibe = Tag::create(['kind' => TagKind::Vibe, 'name' => 'cozy', 'slug' => 'cozy']);
    $place->tags()->attach([
        $cuisine->id => ['source' => 'extraction', 'confidence' => 0.9],
        $vibe->id => ['source' => 'extraction', 'confidence' => 0.8],
    ]);

    Livewire::test(ViewPlace::class, ['record' => $place->getKey()])
        ->assertSee('Discovery tags')
        ->assertSee('ramen')
        ->assertSee('cozy');
});

it('hides the discovery-tags section when a place has none', function () {
    actingAsAdmin();

    $place = Place::factory()->atPoint(51.5, -0.13)->create();

    Livewire::test(ViewPlace::class, ['record' => $place->getKey()])
        ->assertDontSee('Discovery tags');
});

// --- Actions ---

it('approves a pending place as new (pending → active)', function () {
    actingAsAdmin();

    $place = Place::factory()->atPoint(51.5, -0.13)->create();

    Livewire::test(ViewPlace::class, ['record' => $place->getKey()])
        ->callAction('approve');

    expect($place->fresh()->status)->toBe(PlaceStatus::Active);
});

it('hides a place and drops it from public surfaces', function () {
    actingAsAdmin();

    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create();

    Livewire::test(ViewPlace::class, ['record' => $place->getKey()])
        ->callAction('hide');

    $place->refresh();
    expect($place->status)->toBe(PlaceStatus::Hidden)
        ->and(Place::query()->publiclyVisible()->whereKey($place->id)->exists())->toBeFalse()
        ->and($place->shouldBeSearchable())->toBeFalse();
});

it('restores a hidden place with provenance to the review queue', function () {
    actingAsAdmin();

    $place = Place::factory()->atPoint(51.5, -0.13)->create(['status' => PlaceStatus::Hidden->value]);
    PlaceSource::factory()->create(['place_id' => $place->id, 'published_at' => now()]); // a source vouches for it

    Livewire::test(ViewPlace::class, ['record' => $place->getKey()])
        ->callAction('restore');

    expect($place->fresh()->status)->toBe(PlaceStatus::Pending);
});

it('refuses to restore a hidden place that has lost all provenance', function () {
    actingAsAdmin();

    // Hidden and sourceless — restoring would put a ghost pin back on the map.
    $place = Place::factory()->atPoint(51.5, -0.13)->create(['status' => PlaceStatus::Hidden->value]);

    Livewire::test(ViewPlace::class, ['record' => $place->getKey()])
        ->callAction('restore');

    expect($place->fresh()->status)->toBe(PlaceStatus::Hidden);
});

it('merges a pending place into a candidate and records the acting admin', function () {
    $admin = actingAsAdmin();

    $loser = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Lanzhou Noodles']);
    $winner = Place::factory()->active()->atPoint(51.5002, -0.1301)->create(['name' => 'Lanzhou Noodle House']);
    PlaceSource::factory()->primary()->create(['place_id' => $loser->id]);
    PlaceSource::factory()->primary()->create(['place_id' => $winner->id]);

    Livewire::test(ViewPlace::class, ['record' => $loser->getKey()])
        ->callAction('merge', data: ['target_place_id' => $winner->id])
        ->assertHasNoActionErrors();

    $loser->refresh();
    expect($loser->status)->toBe(PlaceStatus::Merged)
        ->and($loser->merged_into_place_id)->toBe($winner->id)
        ->and($winner->fresh()->shares_count)->toBe(2)
        ->and(PlaceMerge::sole()->performed_by_user_id)->toBe($admin->id);
});

it('rejects a merge target that is not in the candidate list', function () {
    actingAsAdmin();

    $loser = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Lanzhou Noodles']);
    // Unrelated place on the other side of the world — not a candidate.
    $stranger = Place::factory()->active()->atPoint(-33.87, 151.21)->create(['name' => 'Sydney Pies']);

    Livewire::test(ViewPlace::class, ['record' => $loser->getKey()])
        ->callAction('merge', data: ['target_place_id' => $stranger->id]);

    expect($loser->fresh()->status)->toBe(PlaceStatus::Pending)
        ->and(PlaceMerge::count())->toBe(0);
});

it('unmerges a wrongly merged place from its view page', function () {
    actingAsAdmin();

    $loser = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Lanzhou Noodles']);
    $winner = Place::factory()->active()->atPoint(51.5002, -0.1301)->create(['name' => 'Lanzhou Noodle House']);
    PlaceSource::factory()->primary()->create(['place_id' => $loser->id]);

    Livewire::test(ViewPlace::class, ['record' => $loser->getKey()])
        ->callAction('merge', data: ['target_place_id' => $winner->id]);

    Livewire::test(ViewPlace::class, ['record' => $loser->getKey()])
        ->callAction('unmerge');

    $loser->refresh();
    expect($loser->status)->toBe(PlaceStatus::Pending)
        ->and($loser->merged_into_place_id)->toBeNull()
        ->and($loser->shares_count)->toBe(1)
        ->and(PlaceMerge::sole()->undone_at)->not->toBeNull();
});

it('hides the unmerge action once the merge is already undone', function () {
    actingAsAdmin();

    $loser = Place::factory()->atPoint(51.5, -0.13)->create(['name' => 'Lanzhou Noodles']);
    $winner = Place::factory()->active()->atPoint(51.5002, -0.1301)->create(['name' => 'Lanzhou Noodle House']);

    Livewire::test(ViewPlace::class, ['record' => $loser->getKey()])
        ->callAction('merge', data: ['target_place_id' => $winner->id]);
    PlaceMerge::sole()->update(['undone_at' => now()]);

    Livewire::test(ViewPlace::class, ['record' => $loser->getKey()])
        ->assertActionHidden('unmerge');
});
