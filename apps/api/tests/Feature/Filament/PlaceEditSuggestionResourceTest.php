<?php

use App\Enums\SuggestionStatus;
use App\Filament\Resources\PlaceEditSuggestions\Pages\ListPlaceEditSuggestions;
use App\Filament\Resources\PlaceEditSuggestions\PlaceEditSuggestionResource;
use App\Models\Place;
use App\Models\PlaceEdit;
use App\Models\PlaceEditSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('blocks non-admins from the queue', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin/place-edit-suggestions')->assertForbidden();
});

it('shows the diff in the row, because that is the whole decision', function () {
    $this->actingAs(User::factory()->admin()->create());
    $place = Place::factory()->create(['name' => 'Cantina Vieja']);
    PlaceEditSuggestion::factory()->create([
        'place_id' => $place->id,
        'changes' => [
            'phone' => ['from' => null, 'to' => '+598 2 900 0000'],
            'opening_hours_json' => ['from' => null, 'to' => ['Lu-Vi 12:00–15:00']],
        ],
    ]);

    Livewire::test(ListPlaceEditSuggestions::class)
        ->assertSuccessful()
        // The proposed values, and the em-dash standing in for the empty ones —
        // a queue that showed only field names would be unreviewable.
        ->assertSee('phone: — → +598 2 900 0000')
        // Array values are joined rather than rendered as "Array".
        ->assertSee('opening_hours_json: — → Lu-Vi 12:00–15:00');
});

it('applies the change on approve, through the same editor a curator uses', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
    $suggestion = PlaceEditSuggestion::factory()->create([
        'place_id' => $place->id,
        'changes' => ['phone' => ['from' => '+598 2 111 1111', 'to' => '+598 2 900 0000']],
    ]);

    Livewire::test(ListPlaceEditSuggestions::class)
        ->callTableAction('approve', $suggestion);

    $place->refresh();
    $suggestion->refresh();

    expect($place->phone)->toBe('+598 2 900 0000')
        ->and($place->isFieldLocked('phone'))->toBeTrue()
        ->and($suggestion->status)->toBe(SuggestionStatus::Approved)
        ->and($suggestion->reviewed_by_user_id)->toBe($admin->id);

    $edit = PlaceEdit::query()->where('place_id', $place->id)->sole();
    expect($edit->origin)->toBe(PlaceEdit::ORIGIN_MANUAL)
        ->and($edit->user_id)->toBe($admin->id);
});

it('records the reason on reject and leaves the place alone', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
    $suggestion = PlaceEditSuggestion::factory()->create([
        'place_id' => $place->id,
        'changes' => ['phone' => ['from' => '+598 2 111 1111', 'to' => '+598 2 900 0000']],
    ]);

    Livewire::test(ListPlaceEditSuggestions::class)
        ->callTableAction('reject', $suggestion, ['reason' => 'No source supports this number.']);

    $suggestion->refresh();
    expect($suggestion->status)->toBe(SuggestionStatus::Rejected)
        ->and($suggestion->reason)->toBe('No source supports this number.')
        ->and($suggestion->reviewed_by_user_id)->toBe($admin->id)
        ->and($place->fresh()->phone)->toBe('+598 2 111 1111');
});

/**
 * A rejection with no reason is the failure this form exists to prevent: the
 * only record of why a correction was refused is the sentence the moderator
 * types, and a blank one leaves a suggestion that reads as arbitrary.
 */
it('refuses to reject without a reason', function () {
    $this->actingAs(User::factory()->admin()->create());
    $suggestion = PlaceEditSuggestion::factory()->create();

    Livewire::test(ListPlaceEditSuggestions::class)
        ->callTableAction('reject', $suggestion, ['reason' => ''])
        ->assertHasTableActionErrors(['reason']);

    expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Pending);
});

it('offers no decision on a row somebody already settled', function () {
    $this->actingAs(User::factory()->admin()->create());
    $settled = PlaceEditSuggestion::factory()->approved()->create();

    Livewire::test(ListPlaceEditSuggestions::class)
        // Off the default queue, so the filter has to be moved to reach it at
        // all — which is itself the first half of "already settled".
        ->filterTable('status', SuggestionStatus::Approved->value)
        ->assertCanSeeTableRecords([$settled])
        ->assertTableActionHidden('approve', $settled)
        ->assertTableActionHidden('reject', $settled);
});

it('defaults to the pending queue and hides what has been decided', function () {
    $this->actingAs(User::factory()->admin()->create());
    $open = PlaceEditSuggestion::factory()->create(['place_id' => Place::factory()->create(['name' => 'Still Open'])]);
    PlaceEditSuggestion::factory()->approved()->create(['place_id' => Place::factory()->create(['name' => 'Already Done'])]);

    Livewire::test(ListPlaceEditSuggestions::class)
        ->assertCanSeeTableRecords([$open])
        ->assertSee('Still Open')
        ->assertDontSee('Already Done');
});

/**
 * Approving a proposal the place has already caught up with is a real outcome —
 * `PlaceEditor` writes nothing — and in the table it looks identical to a
 * successful apply: the row goes green either way. Without the warning, a
 * moderator walks away believing they changed something.
 */
it('says so out loud when an approval changes nothing', function () {
    $this->actingAs(User::factory()->admin()->create());

    $place = Place::factory()->create(['phone' => '+598 2 900 0000']);
    $suggestion = PlaceEditSuggestion::factory()->create([
        'place_id' => $place->id,
        // Proposes exactly what the place already holds — somebody fixed it first.
        'changes' => ['phone' => ['from' => '+598 2 111 1111', 'to' => '+598 2 900 0000']],
    ]);

    Livewire::test(ListPlaceEditSuggestions::class)
        ->callTableAction('approve', $suggestion)
        ->assertNotified('Approved — nothing to change');

    $suggestion->refresh();
    expect($suggestion->status)->toBe(SuggestionStatus::Approved)
        // No audit row is invented for a write that did not happen.
        ->and($suggestion->place_edit_id)->toBeNull()
        ->and(PlaceEdit::query()->where('place_id', $place->id)->count())->toBe(0);
});

/**
 * The queue is read-and-decide. A moderator who could hand-write or delete a
 * suggestion would be editing the place through a second door — one that skips
 * the field allow-list and the audit trail the Places resource enforces.
 */
it('offers no way to create, edit or delete a suggestion', function () {
    $this->actingAs(User::factory()->admin()->create());
    $suggestion = PlaceEditSuggestion::factory()->create();

    expect(PlaceEditSuggestionResource::canCreate())->toBeFalse()
        ->and(PlaceEditSuggestionResource::canEdit($suggestion))->toBeFalse()
        ->and(PlaceEditSuggestionResource::canDelete($suggestion))->toBeFalse();

    // And the routes those pages would live at do not exist.
    expect(array_keys(PlaceEditSuggestionResource::getPages()))->toBe(['index']);
});
