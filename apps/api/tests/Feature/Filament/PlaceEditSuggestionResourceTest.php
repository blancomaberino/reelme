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
 * A note-only row (T-112) is the whole finding — there is no diff to read, so
 * if the queue does not render the prose it renders nothing at all.
 */
it('shows the note in the row, and marks the row as a note', function () {
    $this->actingAs(User::factory()->admin()->create());
    PlaceEditSuggestion::factory()->noteOnly('The pin is on the wrong side of the street.')->create();
    // The contrast case, in the same table: the badge is only evidence of
    // anything if it says something DIFFERENT for a field patch. Asserting the
    // absence of "Field edit" would not do it — the filter's own option label
    // carries that string whether or not a row does.
    PlaceEditSuggestion::factory()->create([
        'changes' => ['phone' => ['from' => null, 'to' => '+598 2 900 0000']],
    ]);

    Livewire::test(ListPlaceEditSuggestions::class)
        ->assertSuccessful()
        ->assertSee('The pin is on the wrong side of the street.')
        // Visibly distinct from a field patch, so a reviewer knows before
        // opening anything that this one needs a person to go and look. The
        // badge deliberately does not read "Note" — that is the column's own
        // label, and this assertion would then pass on an empty table.
        ->assertSee('Note only')
        ->assertSee('Field edit')
        // And the empty diff still shows its placeholder rather than a blank cell.
        ->assertSee('(nothing)');
});

/**
 * The queue opens on PENDING, where "Reviewer note" and "Reviewed by" are empty
 * on every row — and eleven columns in one table left the note about forty
 * pixels wide, one word per line. Hiding the two dead ones by default is what
 * buys prose the room to be read; a future edit that un-hides them takes it
 * straight back.
 */
it('opens with the note visible and the always-empty review columns hidden', function () {
    $this->actingAs(User::factory()->admin()->create());
    $suggestion = PlaceEditSuggestion::factory()->noteOnly()->create();

    // On the RENDERED headers, not on `assertTableColumnHidden` — that asks the
    // column's `hidden()` closures and knows nothing about the toggle state,
    // so it passes for a column sitting right there on the page (verified: it
    // did). What a moderator sees is the header row.
    Livewire::test(ListPlaceEditSuggestions::class)
        ->assertSee('Note')
        ->assertSee('Proposed change')
        ->assertDontSee('Reviewer note')
        ->assertDontSee('Reviewed by')
        // The floor that makes the column readable. A percentage does not
        // survive `table-layout: auto` — measured on the real queue at the same
        // 69px it was trying to fix.
        ->assertTableColumnHasExtraAttributes('note', ['style' => 'min-width: 20rem'], $suggestion);
});

/**
 * The "with a note" filter, exercised BOTH ways.
 *
 * Its `queries()` arms are hand-written SQL, so nothing else in the build knows
 * whether they are the right way round — a swapped pair renders a filter that
 * works, returns rows, and answers the opposite question. Rendering the table
 * proves only that the filter compiles.
 */
it('filters the queue down to the rows carrying a note, and back', function () {
    $this->actingAs(User::factory()->admin()->create());

    $withNote = PlaceEditSuggestion::factory()->noteOnly()->create();
    $fieldOnly = PlaceEditSuggestion::factory()->create();

    Livewire::test(ListPlaceEditSuggestions::class)
        ->filterTable('note', true)
        ->assertCanSeeTableRecords([$withNote])
        ->assertCanNotSeeTableRecords([$fieldOnly])
        ->filterTable('note', false)
        ->assertCanSeeTableRecords([$fieldOnly])
        ->assertCanNotSeeTableRecords([$withNote])
        // Blank is "don't filter", not "match nothing" — the arm most easily
        // written as a no-op that silently empties the queue.
        ->removeTableFilter('note')
        ->assertCanSeeTableRecords([$withNote, $fieldOnly]);
});

it('settles a note-only row with Actioned and records what was done', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $place = Place::factory()->create(['phone' => '+598 2 111 1111']);
    $suggestion = PlaceEditSuggestion::factory()->noteOnly()->create(['place_id' => $place->id]);

    Livewire::test(ListPlaceEditSuggestions::class)
        ->callTableAction('actioned', $suggestion, ['reason' => 'Confirmed closed by phone; hid the place.'])
        ->assertNotified('Suggestion actioned');

    $suggestion->refresh();
    expect($suggestion->status)->toBe(SuggestionStatus::Actioned)
        ->and($suggestion->reason)->toBe('Confirmed closed by phone; hid the place.')
        ->and($suggestion->reviewed_by_user_id)->toBe($admin->id)
        // The row is settled; the place was dealt with by hand, not by this verb.
        ->and($place->fresh()->phone)->toBe('+598 2 111 1111')
        ->and(PlaceEdit::query()->where('place_id', $place->id)->count())->toBe(0);
});

it('refuses to mark something actioned without saying what was done', function () {
    $this->actingAs(User::factory()->admin()->create());
    $suggestion = PlaceEditSuggestion::factory()->noteOnly()->create();

    Livewire::test(ListPlaceEditSuggestions::class)
        ->callTableAction('actioned', $suggestion, ['reason' => ''])
        ->assertHasTableActionErrors(['reason']);

    expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Pending);
});

/**
 * The two verbs must not be interchangeable. Actioned on a row proposing a
 * phone number would settle a real correction with nothing written to the
 * place; Approve on a note-only row would claim an edit that never happened.
 */
it('offers each verb only to the kind of row it is for', function () {
    $this->actingAs(User::factory()->admin()->create());

    $noteOnly = PlaceEditSuggestion::factory()->noteOnly()->create();
    $withPatch = PlaceEditSuggestion::factory()->create([
        'changes' => ['phone' => ['from' => null, 'to' => '+598 2 900 0000']],
        'note' => 'And the hours are wrong too.',
    ]);

    Livewire::test(ListPlaceEditSuggestions::class)
        ->assertCanSeeTableRecords([$noteOnly, $withPatch])
        ->assertTableActionHidden('approve', $noteOnly)
        ->assertTableActionVisible('actioned', $noteOnly)
        ->assertTableActionVisible('approve', $withPatch)
        ->assertTableActionHidden('actioned', $withPatch)
        // Rejection stays available to both — it is the abuse path for prose.
        ->assertTableActionVisible('reject', $noteOnly);
});

it('puts the note in front of the moderator approving the fields beside it', function () {
    $this->actingAs(User::factory()->admin()->create());

    $suggestion = PlaceEditSuggestion::factory()->create([
        'changes' => ['phone' => ['from' => null, 'to' => '+598 2 900 0000']],
        'note' => 'They also moved to the corner unit.',
    ]);

    // Approving settles the WHOLE row, note included, so the words being closed
    // have to be readable at the moment of closing them.
    Livewire::test(ListPlaceEditSuggestions::class)
        ->mountTableAction('approve', $suggestion)
        ->assertSee('They also moved to the corner unit.');
});

it('leaves an actioned row undecidable, and says who settled it and how', function () {
    $this->actingAs(User::factory()->admin()->create());
    $reviewer = User::factory()->admin()->create(['username' => 'the_moderator']);
    $settled = PlaceEditSuggestion::factory()->noteOnly()->actioned('Hid the place; confirmed closed.')
        ->create(['reviewed_by_user_id' => $reviewer->id]);

    Livewire::test(ListPlaceEditSuggestions::class)
        ->filterTable('status', SuggestionStatus::Actioned->value)
        ->assertCanSeeTableRecords([$settled])
        // The two columns hidden on the pending queue are the interesting ones
        // HERE, so the toggle has to actually bring them back — including the
        // `reviewedBy` relation behind one of them, which nothing else loads
        // now that the column is off by default.
        ->toggleAllTableColumns()
        ->assertSee('Hid the place; confirmed closed.')
        ->assertSee('the_moderator')
        ->assertTableActionHidden('actioned', $settled)
        ->assertTableActionHidden('approve', $settled)
        ->assertTableActionHidden('reject', $settled);
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
