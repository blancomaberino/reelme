<?php

use App\Filament\Resources\Places\Pages\EditPlace;
use App\Filament\Resources\Places\Pages\ViewPlace;
use App\Models\Place;
use App\Models\PlaceEdit;
use App\Models\User;
use App\Services\Geo\BusinessDetails;
use App\Services\Geo\FakeGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function editAsAdmin(): User
{
    $admin = User::factory()->admin()->create();
    test()->actingAs($admin);

    return $admin;
}

it('blocks non-admins from the edit page', function () {
    $this->actingAs(User::factory()->create());
    $place = Place::factory()->create();

    $this->get("/admin/places/{$place->getKey()}/edit")->assertForbidden();
});

it('saves a manual edit through PlaceEditor: locks the changed field and audits it', function () {
    $admin = editAsAdmin();
    $place = Place::factory()->create(['name' => 'Old Name', 'phone' => null]);

    Livewire::test(EditPlace::class, ['record' => $place->getKey()])
        ->fillForm(['name' => 'New Name', 'phone' => '+34 600 000 000'])
        ->call('save')
        ->assertHasNoFormErrors();

    $place->refresh();
    expect($place->name)->toBe('New Name')
        ->and($place->phone)->toBe('+34 600 000 000')
        ->and($place->isFieldLocked('name'))->toBeTrue()
        ->and($place->isFieldLocked('phone'))->toBeTrue();

    $edit = PlaceEdit::query()->where('place_id', $place->id)->sole();
    expect($edit->origin)->toBe(PlaceEdit::ORIGIN_MANUAL)
        ->and($edit->user_id)->toBe($admin->id)
        ->and($edit->changes)->toHaveKeys(['name', 'phone']);
});

it('renders the curation + audit sections on the view page', function () {
    editAsAdmin();
    $auditor = User::factory()->admin()->create(['name' => 'Zelda Auditrix']);
    $place = Place::factory()->create([
        'image_url' => 'https://cdn.example/main.jpg',
        'locked_fields' => ['cuisine_primary'],
        'enriched_at' => now(),
    ]);
    PlaceEdit::factory()->create([
        'place_id' => $place->id,
        'user_id' => $auditor->id,
        'origin' => PlaceEdit::ORIGIN_ENRICHMENT,
        'changes' => ['website' => ['from' => null, 'to' => 'https://x.example']],
    ]);

    Livewire::test(ViewPlace::class, ['record' => $place->getKey()])
        ->assertSuccessful()
        // Distinctive to the new sections: the raw field name only appears in the
        // locked-fields badge, and the auditor's name only in the audit row.
        ->assertSee('cuisine_primary')
        ->assertSee('Zelda Auditrix');
});

it('runs the enrich action and pulls fresh values into the form', function () {
    editAsAdmin();
    config([
        'places.enrich.website.enabled' => false,
        'reviews.sources.google.enabled' => false,
        'reviews.sources.trustpilot.enabled' => false,
    ]);
    Http::fake();

    $place = Place::factory()->withGooglePlaceId('gp_edit')->create(['phone' => null]);
    bindGeocoder((new FakeGeocoder)->seedBusinessDetails('gp_edit', new BusinessDetails(phone: '+351 21 000 0000')));

    Livewire::test(EditPlace::class, ['record' => $place->getKey()])
        ->callAction('enrich')
        ->assertHasNoErrors()
        // Proves afterEnrichment() pulled the fresh value back into the open form,
        // not just the DB (the whole reason EditPlace overrides the hook).
        ->assertFormSet(['phone' => '+351 21 000 0000']);

    expect($place->refresh()->phone)->toBe('+351 21 000 0000')
        ->and($place->enriched_at)->not->toBeNull();
});
