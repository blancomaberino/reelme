<?php

use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Filament\Resources\Places\Pages\ListPlaces;
use App\Filament\Resources\Shares\Pages\ListShares;
use App\Jobs\ExtractPlaceData;
use App\Jobs\PublishShare;
use App\Jobs\ResolvePlace;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lets an admin bulk force-reprocess shares from the Shares table', function () {
    Bus::fake();
    $this->actingAs(User::factory()->admin()->create());

    $place = Place::factory()->create(['status' => PlaceStatus::Pending, 'shares_count' => 1]);
    $share = publishedShare($place);

    Livewire::test(ListShares::class)
        ->callTableBulkAction('forceReprocess', [$share]);

    expect($share->fresh()->status)->toBe(ShareStatus::Fetching)
        ->and(PlaceSource::where('share_id', $share->id)->count())->toBe(0);
    Bus::assertChained([ExtractPlaceData::class, ResolvePlace::class, PublishShare::class]);
});

it('lets an admin bulk take down shares from the Shares table', function () {
    $this->actingAs(User::factory()->admin()->create());

    $place = Place::factory()->create(['status' => PlaceStatus::Pending, 'shares_count' => 1]);
    $share = publishedShare($place);

    Livewire::test(ListShares::class)
        ->callTableBulkAction('takeDown', [$share]);

    expect($share->fresh()->status)->toBe(ShareStatus::Rejected)
        ->and($place->fresh()->status)->toBe(PlaceStatus::Removed);
});

it('lets an admin take down and restore places from the Places table', function () {
    $this->actingAs(User::factory()->admin()->create());

    $place = Place::factory()->create(['status' => PlaceStatus::Pending]);
    publishedShare($place);

    Livewire::test(ListPlaces::class)
        ->callTableBulkAction('takeDown', [$place]);
    expect($place->fresh()->status)->toBe(PlaceStatus::Removed);

    Livewire::test(ListPlaces::class)
        ->filterTable('review_queue', false) // the default filter hides removed places
        ->callTableBulkAction('restore', [$place]);
    expect($place->fresh()->status)->toBe(PlaceStatus::Pending);
});

it('wires the per-record share actions (force reprocess + take down)', function () {
    Bus::fake();
    $this->actingAs(User::factory()->admin()->create());

    $reprocess = publishedShare(Place::factory()->create(['status' => PlaceStatus::Pending, 'shares_count' => 1]));
    Livewire::test(ListShares::class)->callTableAction('forceReprocess', $reprocess);
    expect($reprocess->fresh()->status)->toBe(ShareStatus::Fetching);
    Bus::assertChained([ExtractPlaceData::class, ResolvePlace::class, PublishShare::class]);

    $takedown = publishedShare(Place::factory()->create(['status' => PlaceStatus::Pending, 'shares_count' => 1]));
    Livewire::test(ListShares::class)->callTableAction('takeDown', $takedown);
    expect($takedown->fresh()->status)->toBe(ShareStatus::Rejected);
});

it('wires the per-record place actions (take down + restore)', function () {
    $this->actingAs(User::factory()->admin()->create());

    $place = Place::factory()->create(['status' => PlaceStatus::Pending]);
    publishedShare($place);

    Livewire::test(ListPlaces::class)->callTableAction('takeDown', $place);
    expect($place->fresh()->status)->toBe(PlaceStatus::Removed);

    Livewire::test(ListPlaces::class)
        ->filterTable('review_queue', false)
        ->callTableAction('restore', $place);
    expect($place->fresh()->status)->toBe(PlaceStatus::Pending);
});

it('forbids a non-admin from reaching the moderation panel', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    $this->get('/admin/shares')->assertForbidden();
});
