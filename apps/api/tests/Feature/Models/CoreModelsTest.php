<?php

use App\Enums\AnalysisEngine;
use App\Enums\AnalysisStatus;
use App\Enums\MediaKind;
use App\Enums\Platform;
use App\Enums\PostPrivacy;
use App\Enums\ShareStatus;
use App\Models\AnalysisRun;
use App\Models\Influencer;
use App\Models\MediaAsset;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// --- Factories produce valid rows ---

it('creates all five entities via factories', function () {
    expect(Influencer::factory()->create())->toBeInstanceOf(Influencer::class)
        ->and(SourcePost::factory()->create())->toBeInstanceOf(SourcePost::class)
        ->and(Share::factory()->create())->toBeInstanceOf(Share::class)
        ->and(MediaAsset::factory()->create())->toBeInstanceOf(MediaAsset::class)
        ->and(AnalysisRun::factory()->create())->toBeInstanceOf(AnalysisRun::class);
});

// --- Relations resolve both directions ---

it('wires influencer ⇄ source_posts and claimedBy', function () {
    $user = User::factory()->create();
    $influencer = Influencer::factory()->create(['claimed_by_user_id' => $user->id]);
    $post = SourcePost::factory()->create(['influencer_id' => $influencer->id]);

    expect($influencer->sourcePosts->pluck('id'))->toContain($post->id)
        ->and($post->influencer->id)->toBe($influencer->id)
        ->and($influencer->claimedBy->id)->toBe($user->id);
});

it('wires share ⇄ user / source_post / analysis_runs', function () {
    $share = Share::factory()->create();
    $run = AnalysisRun::factory()->create(['share_id' => $share->id]);

    expect($share->user)->toBeInstanceOf(User::class)
        ->and($share->sourcePost)->toBeInstanceOf(SourcePost::class)
        ->and($share->analysisRuns->pluck('id'))->toContain($run->id)
        ->and($run->share->id)->toBe($share->id);
});

it('wires source_post ⇄ media_assets', function () {
    $post = SourcePost::factory()->create();
    $asset = MediaAsset::factory()->create(['source_post_id' => $post->id]);

    expect($post->mediaAssets->pluck('id'))->toContain($asset->id)
        ->and($asset->sourcePost->id)->toBe($post->id);
});

// --- Enum + jsonb + decimal casts round-trip ---

it('casts enums, jsonb, and decimals', function () {
    $post = SourcePost::factory()->create([
        'platform' => Platform::Tiktok,
        'privacy' => PostPrivacy::Public,
        'oembed_json' => ['author' => 'x', 'html' => '<iframe>'],
    ]);
    $post->refresh();

    expect($post->platform)->toBe(Platform::Tiktok)
        ->and($post->privacy)->toBe(PostPrivacy::Public)
        // jsonb doesn't preserve key order — assert by key, not array identity.
        ->and($post->oembed_json['author'])->toBe('x')
        ->and($post->oembed_json['html'])->toBe('<iframe>');

    $run = AnalysisRun::factory()->succeeded()->create([
        'engine' => AnalysisEngine::OpenRouter,
        'cost_usd' => '0.012345',
        'overall_confidence' => '0.900',
    ]);
    $run->refresh();

    expect($run->engine)->toBe(AnalysisEngine::OpenRouter)
        ->and($run->status)->toBe(AnalysisStatus::Succeeded)
        ->and($run->cost_usd)->toBe('0.012345')
        ->and($run->overall_confidence)->toBe('0.900')
        ->and($run->result_json)->toBeArray();

    $asset = MediaAsset::factory()->keyframe()->create();
    expect($asset->fresh()->kind)->toBe(MediaKind::Keyframe);

    $share = Share::factory()->published()->create();
    expect($share->fresh()->status)->toBe(ShareStatus::Published);
});

// --- DB-level constraints ---

it('rejects a duplicate (platform, external_id) source_post', function () {
    SourcePost::factory()->create(['platform' => Platform::Instagram, 'external_id' => 'ABC123']);

    SourcePost::factory()->create(['platform' => Platform::Instagram, 'external_id' => 'ABC123']);
})->throws(QueryException::class);

it('rejects a duplicate (user_id, source_post_id) share', function () {
    $share = Share::factory()->create();

    Share::factory()->create([
        'user_id' => $share->user_id,
        'source_post_id' => $share->source_post_id,
    ]);
})->throws(QueryException::class);

it('rejects an invalid status via the CHECK constraint', function () {
    $share = Share::factory()->create();

    DB::table('shares')->where('id', $share->id)->update(['status' => 'not_a_status']);
})->throws(QueryException::class);

it('rejects an invalid platform enum via the CHECK constraint', function () {
    DB::table('source_posts')->insert([
        'platform' => 'myspace',
        'external_id' => 'x1',
        'url' => 'https://example.com',
        'privacy' => 'unknown',
        'fetch_status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('enforces case-insensitive uniqueness on influencer handle (citext)', function () {
    Influencer::factory()->create(['platform' => Platform::Instagram, 'handle' => 'FoodieFin']);

    // Same platform, only case differs — citext collides.
    Influencer::factory()->create(['platform' => Platform::Instagram, 'handle' => 'foodiefin']);
})->throws(QueryException::class);

it('rejects a duplicate (sha256, source_post_id) media asset', function () {
    $post = SourcePost::factory()->create();
    $sha = hash('sha256', 'same-bytes');

    MediaAsset::factory()->create(['source_post_id' => $post->id, 'sha256' => $sha]);
    MediaAsset::factory()->create(['source_post_id' => $post->id, 'sha256' => $sha]);
})->throws(QueryException::class);

it('rejects an out-of-range analysis confidence via CHECK', function () {
    $share = Share::factory()->create();

    DB::table('analysis_runs')->insert([
        'share_id' => $share->id,
        'engine' => 'local',
        'model' => 'x',
        'status' => 'succeeded',
        'overall_confidence' => 1.5, // > 1.0
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

// --- Mass-assignment protection (system fields are not fillable) ---

it('does not mass-assign system-controlled fields', function () {
    $share = new Share;

    expect($share->isFillable('source_post_id'))->toBeTrue()
        ->and($share->isFillable('shared_via'))->toBeTrue()
        ->and($share->isFillable('status'))->toBeFalse()
        ->and($share->isFillable('user_id'))->toBeFalse();

    // A controller that (wrongly) forwards raw input can't escalate:
    $share->fill(['source_post_id' => 1, 'status' => 'published', 'user_id' => 999]);
    expect($share->status)->toBeNull()
        ->and($share->user_id)->toBeNull();

    expect((new Influencer)->isFillable('claimed_by_user_id'))->toBeFalse()
        ->and((new AnalysisRun)->isFillable('result_json'))->toBeTrue() // pipeline-written, but never from a request
        ->and((new Share)->isFillable('id'))->toBeFalse();
});
