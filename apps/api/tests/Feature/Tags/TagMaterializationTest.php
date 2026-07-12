<?php

use App\Enums\TagKind;
use App\Models\Place;
use App\Models\Tag;
use App\Services\Places\TagMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// publishTaggedShare() lives in tests/Helpers/PipelineHelpers.php (loaded via
// Pest.php) — it is shared with the Search suite, so it must exist in every
// parallel worker.

it('materializes tags from the extraction snapshot on publish', function () {
    $place = publishTaggedShare();

    // The analyzingShare() fixture snapshot carries cuisines=[chinese].
    $tags = $place->tags;
    expect($tags->pluck('slug')->all())->toContain('chinese');

    $chinese = $tags->firstWhere('slug', 'chinese');
    expect($chinese->kind)->toBe(TagKind::Cuisine)
        ->and($chinese->pivot->source)->toBe('extraction')
        ->and((float) $chinese->pivot->confidence)->toBe(0.9);
});

it('maps every snapshot field to its tag kind and drops junk labels', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create(['cuisine_primary' => null]);

    app(TagMaterializer::class)->materialize($place, [
        'cuisines' => ['Chinese', 'x'],           // 'x' slugs to 1 char → dropped
        'vibe_tags' => ['hole-in-the-wall', ''],
        'dietary_tags' => ['halal'],
        'dishes' => [
            ['name' => 'Beef Noodle Soup', 'shown_in_video' => true],
            ['name' => '  ', 'shown_in_video' => false], // blank → dropped
        ],
    ], 0.8);
    $place->save();

    $byKind = $place->tags()->get()->groupBy(fn (Tag $t) => $t->kind->value);
    expect($byKind->get('cuisine')?->pluck('slug')->all())->toBe(['chinese'])
        ->and($byKind->get('vibe')?->pluck('slug')->all())->toBe(['hole-in-the-wall'])
        ->and($byKind->get('diet')?->pluck('slug')->all())->toBe(['halal'])
        ->and($byKind->get('dish')?->pluck('slug')->all())->toBe(['beef-noodle-soup']);

    // cuisine_primary backfilled from the first cuisine — RAW label (resolver
    // parity, one format for the exact-match filters), not the slug.
    expect($place->cuisine_primary)->toBe('Chinese');
});

it('keeps the max confidence when the same tag is re-attached', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create();
    $materializer = app(TagMaterializer::class);

    $materializer->materialize($place, ['cuisines' => ['ramen']], 0.6);
    $materializer->materialize($place, ['cuisines' => ['ramen']], 0.9);  // upgrade
    $materializer->materialize($place, ['cuisines' => ['ramen']], 0.4);  // never downgrade

    $pivot = $place->tags()->where('slug', 'ramen')->first()->pivot;
    expect((float) $pivot->confidence)->toBe(0.9);
    expect(Tag::where('slug', 'ramen')->count())->toBe(1);
});

it('does not overwrite an existing cuisine_primary', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create(['cuisine_primary' => 'japanese']);

    app(TagMaterializer::class)->materialize($place, ['cuisines' => ['chinese']], 0.9);

    expect($place->cuisine_primary)->toBe('japanese');
});

it('reuses one tag row per (kind, slug) across places', function () {
    $a = Place::factory()->active()->atPoint(51.5, -0.13)->create();
    $b = Place::factory()->active()->atPoint(51.6, -0.14)->create();

    app(TagMaterializer::class)->materialize($a, ['cuisines' => ['Sushi']], 0.7);
    app(TagMaterializer::class)->materialize($b, ['cuisines' => ['sushi']], 0.8);

    expect(Tag::where('slug', 'sushi')->count())->toBe(1)
        ->and(Tag::where('slug', 'sushi')->first()->places()->count())->toBe(2);
});

it('backfills tags from existing snapshots via the command, idempotently', function () {
    $place = publishTaggedShare();
    $place->tags()->detach(); // simulate a pre-T-031 place: sources exist, no tags

    $this->artisan('reelmap:tags:backfill')->assertSuccessful();
    $this->artisan('reelmap:tags:backfill')->assertSuccessful(); // safe to re-run

    $pivot = $place->tags()->where('slug', 'chinese')->first()->pivot;
    expect((float) $pivot->confidence)->toBe(0.9)
        ->and($place->tags()->where('slug', 'chinese')->count())->toBe(1);
});

it('caps labels per kind (defense in depth against tag explosion)', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create();

    $labels = array_map(fn ($i) => "cuisine-label-{$i}", range(1, 100));
    app(TagMaterializer::class)->materialize($place, ['cuisines' => $labels], 0.9);

    expect($place->tags()->count())->toBe(32)
        ->and(Tag::count())->toBe(32);
});

it('preserves a manual pivot source on republish', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create();
    $tag = Tag::factory()->ofKind(TagKind::Cuisine)->create(['name' => 'Ramen', 'slug' => 'ramen']);
    $place->tags()->attach($tag->id, ['source' => 'manual', 'confidence' => null]);

    app(TagMaterializer::class)->materialize($place, ['cuisines' => ['ramen']], 0.7);

    $pivot = $place->tags()->where('slug', 'ramen')->first()->pivot;
    expect($pivot->source)->toBe('manual')
        ->and((float) $pivot->confidence)->toBe(0.7);
});
