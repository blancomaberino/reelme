<?php

use App\Enums\PlaceStatus;
use App\Models\Builders\PlaceQueryBuilder;
use App\Models\Place;
use App\Models\User;

/**
 * The T-106 decomposition itself: the seams, not the behaviour behind them.
 *
 * Moving six scopes onto a custom builder and a `saving` hook into a trait are
 * both changes that fail *silently* — the scopes become "undefined method" only
 * on the paths a test happens to walk, and a boot hook that never registers
 * simply stops maintaining its columns. The 1090 existing tests prove the
 * behaviour is unchanged; these prove the wiring is what makes that true.
 */
describe('the custom query builder', function () {
    it('is what every Place query starts on', function () {
        expect(Place::query())->toBeInstanceOf(PlaceQueryBuilder::class);
    });

    it('reaches relationship queries too, so a scope works through a relation', function () {
        // `$user->places()`-style access goes through newEloquentBuilder as well;
        // if it did not, the vocabulary would work on some call sites only.
        expect(Place::query()->publiclyVisible())->toBeInstanceOf(PlaceQueryBuilder::class);
    });

    it('keeps the scopes chainable in either order', function () {
        $user = User::factory()->create();

        expect(Place::query()->publiclyVisible()->mine($user))->toBeInstanceOf(PlaceQueryBuilder::class)
            ->and(Place::query()->mine($user)->publiclyVisible())->toBeInstanceOf(PlaceQueryBuilder::class);
    });

    it('still narrows rather than widens when tags are stacked (AND, not OR)', function () {
        // The invariant the scope's docblock promises and the one a move like
        // this could quietly invert.
        $sql = Place::query()->allTagSlugs(['ramen', 'vegan'])->toSql();

        expect(substr_count($sql, 'exists'))->toBe(2);
    });

    it('treats a blank payment-card token as a no-op', function () {
        expect(Place::query()->withPaymentCard('  ')->toSql())
            ->toBe(Place::query()->toSql());
    });

    it('treats an empty tag list as a no-op', function () {
        expect(Place::query()->allTagSlugs([])->toSql())
            ->toBe(Place::query()->toSql());
    });
});

describe('the derived-columns trait', function () {
    it('still maintains normalized_name and slug on save', function () {
        // `booted()` became `bootDerivesNameColumns()`. Eloquent boots trait
        // hooks by convention, so a rename or a namespace slip here would stop
        // the hook registering — with no error, just unmatched places.
        $place = Place::factory()->create(['name' => "Joe's Ltd"]);

        // 'joe s', not 'joes': punctuation becomes a SPACE rather than being
        // deleted, so the apostrophe splits the token. Pinned as-is because the
        // trigram index was built against this output — "fixing" it would
        // silently stop matching every place already normalized the old way.
        expect($place->normalized_name)->toBe('joe s')
            ->and($place->slug)->toStartWith('joes-');
    });

    it('re-normalizes when the name changes', function () {
        $place = Place::factory()->create(['name' => 'Old Name']);
        $place->update(['name' => 'Café Público']);

        expect($place->fresh()->normalized_name)->toBe('cafe publico');
    });

    it('does not re-slug an existing place on rename (URLs stay stable)', function () {
        $place = Place::factory()->create(['name' => 'First']);
        $slug = $place->slug;
        $place->update(['name' => 'Second']);

        expect($place->fresh()->slug)->toBe($slug);
    });

    it('normalizes accents, punctuation and legal suffixes', function () {
        expect(Place::normalizeName('Bar Tinta Limited'))->toBe('bar tinta')
            ->and(Place::normalizeName('  Multiple   Spaces  '))->toBe('multiple spaces')
            ->and(Place::normalizeName('!!!'))->toBe('');
    });

    it('does NOT strip a dotted legal abbreviation — a known limit, pinned', function () {
        // The suffix regex is word-boundary based, so 'S.R.L.' has already been
        // split into 's r l' by the punctuation pass and no longer matches
        // \b(srl)\b. Documented rather than fixed: changing normalization
        // invalidates every stored normalized_name and the dedup matches
        // built on them.
        expect(Place::normalizeName('Café Ñandú, S.R.L.'))->toBe('cafe nandu s r l')
            ->and(Place::normalizeName('Café Ñandú SRL'))->toBe('cafe nandu');
    });

    it('mints a unique slug per place even for an identical name', function () {
        $a = Place::factory()->create(['name' => 'Same Name']);
        $b = Place::factory()->create(['name' => 'Same Name']);

        expect($a->slug)->not->toBe($b->slug);
    });
});

describe('the geo trait', function () {
    it('round-trips a point through PostGIS in the right axis order', function () {
        // ST_MakePoint takes (lng, lat). Reversing them is the classic PostGIS
        // bug and puts the pin in the ocean — asymmetric values catch it.
        $place = Place::factory()->create();
        $place->setPoint(-34.9011, -56.1645);
        $place->save();

        $coords = $place->coordinates();
        expect($coords['lat'])->toBeGreaterThan(-35.0)->toBeLessThan(-34.8)
            ->and($coords['lng'])->toBeGreaterThan(-56.3)->toBeLessThan(-56.0);
    });

    it('rejects a non-finite coordinate instead of writing broken SQL', function () {
        expect(fn () => Place::factory()->create()->setPoint(INF, 0))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => Place::factory()->create()->setPoint(0, NAN))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('the Scout trait', function () {
    it('keeps pending and active places searchable and drops tombstones', function () {
        expect(Place::factory()->create(['status' => PlaceStatus::Pending])->shouldBeSearchable())->toBeTrue()
            ->and(Place::factory()->create(['status' => PlaceStatus::Active])->shouldBeSearchable())->toBeTrue();

        $canonical = Place::factory()->create();
        $merged = Place::factory()->create(['merged_into_place_id' => $canonical->id]);
        expect($merged->shouldBeSearchable())->toBeFalse();
    });

    it('projects _geo even when lat/lng were not preselected', function () {
        // The bulk-import path aliases lat/lng; a single-model sync has to fall
        // back to the coordinate query, and that fallback lives in the trait now.
        $place = Place::factory()->create();
        $place->setPoint(-34.9011, -56.1645);
        $place->save();

        $doc = $place->fresh()->toSearchableArray();

        expect($doc['_geo']['lat'])->toBeFloat()->toBeLessThan(0.0)
            ->and($doc['_geo']['lng'])->toBeFloat()->toBeLessThan(0.0)
            ->and($doc)->toHaveKeys(['id', 'name', 'normalized_name', 'slug', 'tags', 'tag_names']);
    });
});
