<?php

use App\Enums\FetchStatus;
use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\User;
use App\Services\Geo\FakeGeocoder;
use App\Services\Geo\Geocoder;
use App\Services\Geo\GeocodeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('stores a pasted caption pre-fetched so the pipeline extracts from it directly', function () {
    Bus::fake(); // assert the source_post shape without running the sync chain
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/shares', [
        'caption' => 'Best pastéis de nata at Manteigaria in Lisbon',
    ])->assertStatus(202)->assertJsonPath('data.requires_manual_input', true);

    $post = Share::latest('id')->first()->sourcePost;
    expect($post->caption)->toBe('Best pastéis de nata at Manteigaria in Lisbon')
        ->and($post->fetch_status)->toBe(FetchStatus::Fetched); // skips the platform fetch
});

it('runs a manual caption share end to end to a published, mapped place', function () {
    Sanctum::actingAs(User::factory()->create());

    // Local model returns a valid extraction; keyless geocoder resolves the name.
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response([
            'message' => ['content' => (string) file_get_contents(base_path('tests/Fixtures/extraction/valid.json'))],
            'prompt_eval_count' => 10,
            'eval_count' => 5,
        ]),
    ]);
    app()->instance(Geocoder::class, (new FakeGeocoder)->seed(
        'Lanzhou Beef Noodle House',
        new GeocodeResult('osm:node:1', 'Lanzhou Beef Noodle House', '45 Gerrard St, London', [
            ['long_name' => 'United Kingdom', 'short_name' => 'GB', 'types' => ['country', 'political']],
        ], 51.5117, -0.1300, ['restaurant'], 0.9),
    ));

    $this->postJson('/api/v1/shares', ['caption' => 'Amazing hand-pulled noodles at Lanzhou Beef Noodle House'])
        ->assertStatus(202);

    $share = Share::latest('id')->first();
    expect($share->status)->toBe(ShareStatus::Published);

    // The place is on the map (pending = unverified single source) with real coords.
    $place = Place::sole();
    expect($place->status)->toBe(PlaceStatus::Pending)
        ->and($place->coordinates())->toBe(['lat' => 51.5117, 'lng' => -0.13])
        ->and(PlaceSource::where('share_id', $share->id)->exists())->toBeTrue();

    // And it now shows up in the Map API for a London viewport.
    $names = collect($this->getJson('/api/v1/map/places?bbox=-0.2,51.45,-0.05,51.55&zoom=16')->json('data.pins'))
        ->pluck('name');
    expect($names)->toContain('Lanzhou Beef Noodle House');
});
