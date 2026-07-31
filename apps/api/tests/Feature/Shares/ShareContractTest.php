<?php

use App\Enums\AnalysisEngine;
use App\Enums\AnalysisStatus;
use App\Enums\ShareStatus;
use App\Models\AnalysisRun;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\ShareStageMetric;
use App\Models\User;
use App\Support\Contracts\ApiSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Contract tests (T-102): GET /shares/{id} is the most complex payload in the
 * system and the mobile app codes to a generated type. Every state the pipeline
 * can leave a share in must validate against packages/contracts/schemas/share.json
 * — the same file `ShareDetail` is generated from.
 */

/** The canonical schema-valid extraction the pipeline persists on a succeeded run. */
function contractExtraction(): array
{
    /** @var array<string, mixed> $data */
    $data = json_decode(pipelineExtractionJson(), true, flags: JSON_THROW_ON_ERROR);

    return $data;
}

function contractShare(ShareStatus $status, User $owner): Share
{
    return Share::factory()->for($owner)->create(['status' => $status]);
}

/** Fetch a live GET /shares/{id} body and assert it validates. */
function assertShareContract(Share $share): array
{
    $data = test()->getJson("/api/v1/shares/{$share->id}")->assertOk()->json('data');
    assertMatchesContract($data, 'share');

    return $data;
}

it('validates against share.json in every pipeline status', function (ShareStatus $status) {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);

    assertShareContract(contractShare($status, $owner));
})->with([
    'pending' => ShareStatus::Pending,
    'fetching' => ShareStatus::Fetching,
    'analyzing' => ShareStatus::Analyzing,
    'review' => ShareStatus::Review,
    'published' => ShareStatus::Published,
    'failed' => ShareStatus::Failed,
    'rejected' => ShareStatus::Rejected,
]);

it('validates a succeeded run — the extraction conforms to the extraction contract', function () {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);

    $share = contractShare(ShareStatus::Published, $owner);
    AnalysisRun::create([
        'share_id' => $share->id,
        'engine' => AnalysisEngine::Local,
        'model' => 'qwen2.5-vl:7b',
        'status' => AnalysisStatus::Succeeded,
        'overall_confidence' => '0.910',
        'result_json' => contractExtraction(),
        'started_at' => now()->subSeconds(20),
        'finished_at' => now(),
    ]);

    $data = assertShareContract($share);

    expect($data['analysis']['confidence'])->toBe(0.91)
        ->and($data['analysis']['extraction']['places'][0]['name'])->not->toBeNull();
});

it('rejects an extraction that drifts from the extraction contract', function () {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);

    $share = contractShare(ShareStatus::Published, $owner);
    AnalysisRun::create([
        'share_id' => $share->id,
        'engine' => AnalysisEngine::Local,
        'model' => 'qwen2.5-vl:7b',
        'status' => AnalysisStatus::Succeeded,
        // The pre-T-102 fixture shape: a single `place` object, no `places`.
        'result_json' => ['place' => ['name' => 'Drifted']],
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $data = $this->getJson("/api/v1/shares/{$share->id}")->assertOk()->json('data');
    $errors = ApiSchema::errors(ApiSchema::validate($data, 'share'));

    // The nested cause must surface, not just the outer anyOf "must be null".
    expect($errors)->toHaveKey('/analysis/extraction')
        ->and(implode(' ', $errors['/analysis/extraction']))->toContain('places');
});

it('validates a run in flight — analysis present, extraction still null', function () {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);

    $share = contractShare(ShareStatus::Analyzing, $owner);
    AnalysisRun::create([
        'share_id' => $share->id,
        'engine' => AnalysisEngine::Local,
        'model' => 'qwen2.5-vl:7b',
        'status' => AnalysisStatus::Running,
        'started_at' => now(),
    ]);

    $data = assertShareContract($share);

    expect($data['analysis']['extraction'])->toBeNull()
        ->and($data['analysis']['confidence'])->toBeNull();
});

it('validates the whole failure taxonomy on failed and review shares', function (string $code, ShareStatus $status) {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);

    $share = Share::factory()->for($owner)->create(['status' => $status, 'failure_reason' => $code]);
    ShareStageMetric::create([
        'share_id' => $share->id,
        'stage' => 'extract',
        'status' => 'failed',
        'started_at' => now()->subSeconds(5),
        'attempt' => 1,
    ]);

    $data = assertShareContract($share);

    expect($data['failure']['code'])->toBe($code)
        ->and($data['failure']['step'])->toBe('extract')
        ->and($data['failure']['message'])->not->toBe('')
        ->and($data['failure']['manual_fallback'])->toBe($status === ShareStatus::Review);
})->with([
    'fetch_unavailable' => ['fetch_unavailable', ShareStatus::Review],
    'fetch_auth_required' => ['fetch_auth_required', ShareStatus::Review],
    'geocode_failed' => ['geocode_failed', ShareStatus::Review],
    'resolve_conflict' => ['resolve_conflict', ShareStatus::Review],
    'media_too_large' => ['media_too_large', ShareStatus::Failed],
    'ffmpeg_error' => ['ffmpeg_error', ShareStatus::Failed],
    'transcribe_error' => ['transcribe_error', ShareStatus::Failed],
    'ollama_unreachable' => ['ollama_unreachable', ShareStatus::Failed],
    'invalid_model_output' => ['invalid_model_output', ShareStatus::Failed],
    'cost_cap_exceeded' => ['cost_cap_exceeded', ShareStatus::Failed],
    'unknown code falls back to generic copy' => ['some_future_code', ShareStatus::Failed],
]);

it('validates a status_history derived from stage metrics', function () {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);

    $share = contractShare(ShareStatus::Published, $owner);
    foreach ([['fetch', 30], ['media', 25], ['extract', 20], ['resolve', 15], ['publish', 10]] as [$stage, $ago]) {
        ShareStageMetric::create([
            'share_id' => $share->id,
            'stage' => $stage,
            'status' => 'succeeded',
            'started_at' => now()->subSeconds($ago),
            'duration_ms' => 1200,
            'attempt' => 1,
        ]);
    }

    $data = assertShareContract($share);

    expect(array_column($data['status_history'], 'status'))
        ->toBe(['pending', 'fetching', 'analyzing', 'published']);
});

it('validates a multi-place share with pending venues', function () {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);

    $share = Share::factory()->for($owner)->published()->create();
    $primary = Place::factory()->active()->atPoint(38.7169, -9.1355)->create(['name' => 'Time Out Market']);
    $second = Place::factory()->active()->atPoint(38.7100, -9.1400)->create(['name' => 'Manteigaria']);
    $candidate = Place::factory()->active()->atPoint(38.7200, -9.1300)->create(['name' => 'A Cevicheria']);

    $sources = collect([$primary, $second])->map(fn (Place $place, int $i) => PlaceSource::factory()->create([
        'share_id' => $share->id,
        'place_id' => $place->id,
        'source_post_id' => $share->source_post_id,
        'is_primary' => $i === 0,
        'published_at' => now(),
    ]));

    $share->published_place_source_id = $sources[0]->id;
    $share->review_meta_json = ['pending' => [[
        'index' => 2,
        'name' => $candidate->name,
        'reason' => 'ambiguous_place',
        'candidates' => [[
            'place_id' => $candidate->id,
            'name' => $candidate->name,
            'address' => 'R. Dom Pedro V 129, Lisboa',
            'distance_m' => 40.5,
            'similarity' => 0.92,
        ]],
    ]]];
    $share->save();

    $data = assertShareContract($share);

    expect($data['places'])->toHaveCount(2)
        ->and($data['place']['id'])->toBe((string) $primary->id)
        ->and($data['places'][0]['id'])->toBe((string) $primary->id)
        ->and($data['pending_place_count'])->toBe(1)
        ->and($data['pending_places'][0]['candidates'][0]['place_id'])->toBe((string) $candidate->id);
});

it('validates a pending venue whose candidate fields are all absent', function () {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);

    // ResolvePlace writes whatever the resolver produced; a name-only venue with
    // a bare candidate must still emit every key (nulled), not omit them.
    $share = Share::factory()->for($owner)->review()->create();
    $share->review_meta_json = ['pending' => [['candidates' => [[]]]]];
    $share->save();

    $data = assertShareContract($share);

    expect($data['pending_places'][0])
        ->toMatchArray(['index' => 0, 'name' => null, 'reason' => null])
        ->and($data['pending_places'][0]['candidates'][0])
        ->toMatchArray(['place_id' => '', 'name' => null, 'address' => null, 'distance_m' => null, 'similarity' => null]);
});

it('validates the shares index rows too', function () {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);
    contractShare(ShareStatus::Published, $owner);
    contractShare(ShareStatus::Review, $owner);

    $rows = $this->getJson('/api/v1/shares')->assertOk()->json('data');
    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect(ApiSchema::errors(ApiSchema::validate($row, 'share')))->toBe([]);
    }
});
