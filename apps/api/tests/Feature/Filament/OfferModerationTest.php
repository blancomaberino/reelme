<?php

use App\Enums\OfferStatus;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\Offers\Pages\ListOffers;
use App\Models\Offer;
use App\Models\Place;
use App\Models\User;
use Livewire\Livewire;

/**
 * Post-hoc offer moderation (T-042, 06 §2.2): admins pause, they never edit or
 * delete. An admin rewriting an offer's terms would make the redemption record
 * — the thing a fee is charged against — no longer match what the diner agreed
 * to, so the page deliberately has only the two reversible actions.
 */
it('lets an admin pause a live offer, removing it from diner browse', function () {
    $this->actingAs(User::factory()->admin()->create());
    $place = Place::factory()->active()->create();
    $offer = Offer::factory()->active()->create(['place_id' => $place->id, 'title' => 'Half-price Tuesdays']);

    Livewire::test(ListOffers::class)->callTableAction('pause', $offer);

    expect($offer->fresh()->status)->toBe(OfferStatus::Paused);

    $titles = collect($this->getJson('/api/v1/offers')->assertOk()->json('data'))->pluck('title');
    expect($titles)->not->toContain('Half-price Tuesdays');
});

it('lets an admin resume a paused offer', function () {
    $this->actingAs(User::factory()->admin()->create());
    $offer = Offer::factory()->paused()->create(['place_id' => Place::factory()->active()]);

    Livewire::test(ListOffers::class)->callTableAction('resume', $offer);

    expect($offer->fresh()->status)->toBe(OfferStatus::Active);
});

it('offers no pause action on an offer that is not live', function () {
    $this->actingAs(User::factory()->admin()->create());
    $draft = Offer::factory()->create(['place_id' => Place::factory()->active()]);

    Livewire::test(ListOffers::class)
        ->assertTableActionHidden('pause', $draft)
        ->assertTableActionHidden('resume', $draft);
});

it('badges the navigation with the number of published offers', function () {
    Offer::factory()->active()->count(2)->create(['place_id' => Place::factory()->active()]);
    Offer::factory()->create(['place_id' => Place::factory()->active()]);

    expect(OfferResource::getNavigationBadge())->toBe('2');
});

it('shows no badge when nothing is published', function () {
    Offer::factory()->create(['place_id' => Place::factory()->active()]);

    expect(OfferResource::getNavigationBadge())->toBeNull();
});

/*
 * Admins pause; they never author or remove. An admin editing an operator's
 * terms would make the redemption record no longer match what the diner agreed
 * to, and deleting one would orphan the fees drawn against it.
 */
it('exposes no create, edit, or delete action to an admin', function () {
    $offer = Offer::factory()->active()->create(['place_id' => Place::factory()->active()]);

    expect(OfferResource::canCreate())->toBeFalse()
        ->and(OfferResource::canEdit($offer))->toBeFalse()
        ->and(OfferResource::canDelete($offer))->toBeFalse();
});

it('keeps the panel closed to non-admins', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin/offers')->assertForbidden();
});
