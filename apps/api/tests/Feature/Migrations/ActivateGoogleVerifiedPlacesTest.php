<?php

use App\Enums\PlaceStatus;
use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runGoogleActivationBackfill(): void
{
    (require database_path('migrations/2026_07_19_000001_activate_google_verified_pending_places.php'))->up();
}

it('activates pending places that are Google-verified, and only those', function () {
    $verified = Place::factory()->create([
        'status' => PlaceStatus::Pending,
        'google_place_id' => 'ChIJverified',
        'google_rating_count' => 120,
    ]);
    $noReviews = Place::factory()->create([
        'status' => PlaceStatus::Pending,
        'google_place_id' => 'ChIJthin',
        'google_rating_count' => 0,
    ]);
    $noGoogle = Place::factory()->create([
        'status' => PlaceStatus::Pending,
        'google_place_id' => null,
        'google_rating_count' => null,
    ]);

    runGoogleActivationBackfill();

    expect($verified->fresh()->status)->toBe(PlaceStatus::Active)
        ->and($noReviews->fresh()->status)->toBe(PlaceStatus::Pending)
        ->and($noGoogle->fresh()->status)->toBe(PlaceStatus::Pending);
});

it('never touches a non-pending place even if Google-verified', function () {
    $hidden = Place::factory()->create([
        'status' => PlaceStatus::Hidden,
        'google_place_id' => 'ChIJhidden',
        'google_rating_count' => 500,
    ]);
    $removed = Place::factory()->create([
        'status' => PlaceStatus::Removed,
        'google_place_id' => 'ChIJremoved',
        'google_rating_count' => 500,
    ]);

    runGoogleActivationBackfill();

    expect($hidden->fresh()->status)->toBe(PlaceStatus::Hidden)
        ->and($removed->fresh()->status)->toBe(PlaceStatus::Removed);
});
