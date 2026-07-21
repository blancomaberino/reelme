<?php

use App\Enums\AnalysisEngine;
use App\Enums\AnalysisStatus;
use App\Models\AnalysisRun;
use App\Models\Place;
use App\Models\Share;
use App\Services\Places\ExtractionCorrector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * ExtractionCorrector (T-097): the review-correction merge/diff engine, unit-
 * tested directly — no HTTP layer. Mirrors ShareController::update()'s old
 * inline logic, now reusable and independently verifiable.
 */
function corrector(): ExtractionCorrector
{
    return app(ExtractionCorrector::class);
}

/** A review share whose winning run carries the given extraction payload. */
function shareWithExtraction(array $result): Share
{
    $share = Share::factory()->review()->create();
    $run = AnalysisRun::create([
        'share_id' => $share->id,
        'engine' => AnalysisEngine::Local,
        'model' => 'test-model',
        'status' => AnalysisStatus::Succeeded,
        'overall_confidence' => 0.6,
        'result_json' => $result,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $share->analysis_run_id = $run->id;
    $share->save();

    return $share->refresh();
}

it('deep-merges a partial correction: nested maps merge, scalars/lists replace', function () {
    $share = shareWithExtraction([
        'confidence' => ['overall' => 0.6, 'geo' => 0.9],
        'places' => [['name' => 'Old Name', 'cuisines' => ['ramen'], 'address' => 'A St']],
    ]);

    $merged = corrector()->applyCorrection($share, [
        'confidence' => ['overall' => 0.95],          // nested map → merges (geo kept)
        'places' => [['name' => 'New Name']],         // place[0] merges (address/cuisines kept)
    ], null);

    expect($merged['confidence'])->toEqualCanonicalizing(['overall' => 0.95, 'geo' => 0.9])
        ->and($merged['places'][0]['name'])->toBe('New Name')
        ->and($merged['places'][0]['address'])->toBe('A St')       // untouched sibling kept
        ->and($merged['places'][0]['cuisines'])->toBe(['ramen']);  // list preserved
});

it('merges places[] element-by-element, preserving untouched venues', function () {
    $share = shareWithExtraction([
        'places' => [
            ['name' => 'Alpha', 'city' => 'Lisbon'],
            ['name' => 'Beta', 'city' => 'Porto'],
        ],
    ]);

    // Only correct the second venue's name.
    $merged = corrector()->applyCorrection($share, ['places' => [1 => ['name' => 'Beta Prime']]], null);

    expect($merged['places'][0])->toEqualCanonicalizing(['name' => 'Alpha', 'city' => 'Lisbon'])  // untouched
        ->and($merged['places'][1]['name'])->toBe('Beta Prime')
        ->and($merged['places'][1]['city'])->toBe('Porto');                      // sibling kept
});

it('folds a manual lat/lng pin into places[0].geo', function () {
    $share = shareWithExtraction(['places' => [['name' => 'Alpha']]]);

    $merged = corrector()->applyCorrection($share, null, ['lat' => 41.1, 'lng' => -8.6]);

    expect($merged['places'][0]['geo'])->toBe(['lat' => 41.1, 'lng' => -8.6])
        ->and($merged['places'][0]['name'])->toBe('Alpha');
});

it('stashes a picked place_id only when it was among the review candidates', function () {
    $picked = Place::factory()->create();
    $share = shareWithExtraction(['places' => [['name' => 'Alpha']]]);
    $share->review_meta_json = ['candidates' => [['place_id' => $picked->id], ['place_id' => 999]]];
    $share->save();

    corrector()->applyCorrection($share->refresh(), null, ['place_id' => $picked->id]);

    expect($share->review_meta_json['picked_place_id'])->toBe($picked->id);
});

it('rejects a place_id the review never offered (no counter-skew attack)', function () {
    $stranger = Place::factory()->create();
    $share = shareWithExtraction(['places' => [['name' => 'Alpha']]]);
    $share->review_meta_json = ['candidates' => [['place_id' => 111]]];
    $share->save();

    expect(fn () => corrector()->applyCorrection($share->refresh(), null, ['place_id' => $stranger->id]))
        ->toThrow(ValidationException::class);
});

it('records one share_corrections row per changed leaf, idempotently', function () {
    $original = ['places' => [['name' => 'Old', 'city' => 'Lisbon']]];
    $share = shareWithExtraction($original);

    $merged = corrector()->applyCorrection($share, ['places' => [['name' => 'New']]], null);
    corrector()->recordCorrections($share, $original, $merged);

    // Only the changed leaf (places.0.name) is recorded — city was untouched.
    expect($share->corrections()->count())->toBe(1);
    $row = $share->corrections()->sole();
    expect($row->field_path)->toBe('places.0.name')
        ->and($row->model_value)->toBe('Old')
        ->and($row->user_value)->toBe('New');

    // Re-recording replaces (never appends) — stays a single row.
    corrector()->recordCorrections($share, $original, $merged);
    expect($share->corrections()->count())->toBe(1);
});
