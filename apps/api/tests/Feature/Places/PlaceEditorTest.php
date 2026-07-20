<?php

use App\Models\Place;
use App\Models\PlaceEdit;
use App\Models\User;
use App\Services\Places\PlaceEditor;

/** The single curated-field write path (T-084): diff → lock → audit. */
function editor(): PlaceEditor
{
    return app(PlaceEditor::class);
}

it('applies a manual patch, locks the changed fields and writes one audit row', function () {
    $user = User::factory()->admin()->create();
    $place = Place::factory()->create(['name' => 'Old', 'phone' => null]);

    $edit = editor()->apply(
        $place,
        ['name' => 'New Name', 'phone' => '+34 600 000 000'],
        PlaceEdit::ORIGIN_MANUAL,
        $user->id,
    );

    $place->refresh();
    expect($place->name)->toBe('New Name')
        ->and($place->phone)->toBe('+34 600 000 000')
        ->and($place->lockedFields())->toEqualCanonicalizing(['name', 'phone'])
        ->and($place->isFieldLocked('name'))->toBeTrue();

    expect($edit)->not->toBeNull()
        ->and($edit->origin)->toBe(PlaceEdit::ORIGIN_MANUAL)
        ->and($edit->user_id)->toBe($user->id)
        ->and($edit->changes)->toHaveKeys(['name', 'phone'])
        ->and($edit->changes['name'])->toBe(['from' => 'Old', 'to' => 'New Name']);
    expect(PlaceEdit::query()->count())->toBe(1);
});

it('ignores non-curated keys and only writes real changes', function () {
    $place = Place::factory()->create(['name' => 'Same', 'city' => 'Lisbon']);

    // status is not curated; name/city unchanged → nothing to do.
    $edit = editor()->apply(
        $place,
        ['name' => 'Same', 'city' => 'Lisbon', 'status' => 'hidden', 'shares_count' => 99],
        PlaceEdit::ORIGIN_MANUAL,
    );

    expect($edit)->toBeNull();
    expect(PlaceEdit::query()->count())->toBe(0);
    expect($place->refresh()->status->value)->not->toBe('hidden'); // non-curated ignored
});

it('never overwrites a locked field from a non-manual origin', function () {
    $place = Place::factory()->create(['phone' => '+34 600 000 000']);
    // A human locked the phone earlier.
    $place->lockFields(['phone']);
    $place->save();

    $edit = editor()->apply(
        $place,
        ['phone' => '+1 555 0000', 'website' => 'https://example.com'],
        PlaceEdit::ORIGIN_ENRICHMENT,
    );

    $place->refresh();
    expect($place->phone)->toBe('+34 600 000 000') // locked → untouched
        ->and($place->website)->toBe('https://example.com'); // unlocked → written
    expect($edit->changes)->toHaveKey('website')
        ->and($edit->changes)->not->toHaveKey('phone');
});

it('does not re-lock fields on an enrichment write', function () {
    $place = Place::factory()->create(['phone' => null]);

    editor()->apply($place, ['phone' => '+34 600 000 000'], PlaceEdit::ORIGIN_ENRICHMENT);

    // Enrichment fills a field but must NOT lock it — a later enrichment may still update it.
    expect($place->refresh()->lockedFields())->toBe([]);
});

it('diffs array fields by content, not identity', function () {
    $place = Place::factory()->create(['opening_hours_json' => ['Mo 09:00–17:00']]);

    $noop = editor()->apply($place, ['opening_hours_json' => ['Mo 09:00–17:00']], PlaceEdit::ORIGIN_MANUAL);
    expect($noop)->toBeNull(); // same content → no change

    $changed = editor()->apply($place, ['opening_hours_json' => ['Mo 10:00–18:00']], PlaceEdit::ORIGIN_MANUAL);
    expect($changed)->not->toBeNull()
        ->and($place->refresh()->isFieldLocked('opening_hours_json'))->toBeTrue();
});
