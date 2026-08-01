<?php

use App\Models\Concerns\LocksFields;

/**
 * Human-owned field locking (T-084), now testable without a database (T-106).
 *
 * The policy is pure: it reads and writes one array attribute. It was only ever
 * exercised through `PlaceEditor` and `BusinessEnricher` feature tests, which
 * means the edge cases — a legacy row with a null column, a `locked_fields`
 * value naming a field that is no longer curated — were covered incidentally if
 * at all. Those are exactly the cases that decide whether an enricher
 * overwrites a curator's work.
 */

/** A minimal host: the trait needs nothing but the attribute. */
class LocksFieldsHost
{
    use LocksFields;

    /** @var array<int, string>|null */
    public ?array $locked_fields = null;
}

it('reports nothing locked on a legacy row whose column was never written', function () {
    expect((new LocksFieldsHost)->lockedFields())->toBe([]);
});

it('locks a curated field and reports it', function () {
    $host = new LocksFieldsHost;
    $host->lockFields(['name', 'phone']);

    expect($host->lockedFields())->toBe(['name', 'phone'])
        ->and($host->isFieldLocked('name'))->toBeTrue()
        ->and($host->isFieldLocked('city'))->toBeFalse();
});

it('silently ignores a field that is not curated', function () {
    // A caller passing a pipeline-owned column must not be able to freeze it.
    $host = new LocksFieldsHost;
    $host->lockFields(['name', 'shares_count', 'status']);

    expect($host->lockedFields())->toBe(['name']);
});

it('merges into the existing set rather than replacing it', function () {
    $host = new LocksFieldsHost;
    $host->lockFields(['name']);
    $host->lockFields(['phone']);

    expect($host->lockedFields())->toBe(['name', 'phone']);
});

it('dedupes a field locked twice', function () {
    $host = new LocksFieldsHost;
    $host->lockFields(['name', 'name']);
    $host->lockFields(['name']);

    expect($host->lockedFields())->toBe(['name']);
});

it('filters a stale locked field out on read, without rewriting the row', function () {
    // If CURATED_FIELDS ever shrinks, an existing row may name a field that is
    // no longer curated. Reading must not resurrect it as a lock.
    $host = new LocksFieldsHost;
    $host->locked_fields = ['name', 'a_field_that_is_no_longer_curated'];

    expect($host->lockedFields())->toBe(['name'])
        ->and($host->isFieldLocked('a_field_that_is_no_longer_curated'))->toBeFalse();
});

it('reports locks in CURATED_FIELDS order, not insertion order', function () {
    // Stable ordering keeps the Filament badge list and any diff deterministic.
    $host = new LocksFieldsHost;
    $host->lockFields(['website', 'name', 'city']);

    expect($host->lockedFields())->toBe(['name', 'city', 'website']);
});

describe('withoutLockedFields', function () {
    it('drops locked keys so a manual override survives an enrichment patch', function () {
        $host = new LocksFieldsHost;
        $host->lockFields(['name', 'phone']);

        expect($host->withoutLockedFields([
            'name' => 'Robo-renamed',
            'phone' => '+000',
            'city' => 'Montevideo',
        ]))->toBe(['city' => 'Montevideo']);
    });

    it('passes non-curated keys through untouched', function () {
        // The pipeline owns these outright; locking must not block them.
        $host = new LocksFieldsHost;
        $host->lockFields(['name']);

        expect($host->withoutLockedFields(['shares_count' => 4, 'name' => 'x']))
            ->toBe(['shares_count' => 4]);
    });

    it('is a no-op when nothing is locked', function () {
        $patch = ['name' => 'Bar Tinta', 'city' => 'Montevideo'];

        expect((new LocksFieldsHost)->withoutLockedFields($patch))->toBe($patch);
    });

    it('preserves a locked key whose patched value is null', function () {
        // array_filter on KEYS only — a null value must not sneak through just
        // because it is falsy.
        $host = new LocksFieldsHost;
        $host->lockFields(['phone']);

        expect($host->withoutLockedFields(['phone' => null, 'city' => null]))
            ->toBe(['city' => null]);
    });
});
