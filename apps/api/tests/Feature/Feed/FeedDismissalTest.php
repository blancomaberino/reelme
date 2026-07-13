<?php

use App\Enums\ShareStatus;
use App\Models\FeedDismissal;
use App\Models\Place;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// publishedShare() lives in tests/Helpers/PipelineHelpers.php (loaded via Pest.php).

it('hides a dismissed share from the viewer feed only, not guests', function () {
    $place = Place::factory()->active()->atPoint(38.7, -9.1)->create(['name' => 'Ramiro']);
    $keep = publishedShare($place, publishedAt: now()->subHour()->toDateTimeString());
    $hide = publishedShare($place, publishedAt: now()->subMinutes(30)->toDateTimeString());

    $me = User::factory()->create();
    Sanctum::actingAs($me);

    // Before hiding: both shares show for the authed viewer.
    expect(collect($this->getJson('/api/v1/feed')->json('data'))->pluck('id')->all())
        ->toEqualCanonicalizing([(string) $keep->id, (string) $hide->id]);

    $this->postJson('/api/v1/feed/hidden', ['share_id' => $hide->id])->assertStatus(201);

    // After: the authed viewer no longer sees it…
    expect(collect($this->getJson('/api/v1/feed')->json('data'))->pluck('id')->all())
        ->toBe([(string) $keep->id]);

    // …but a guest (no token) still sees both — the hide is per-user.
    $this->app['auth']->forgetGuards();
    expect(collect($this->getJson('/api/v1/feed')->json('data'))->pluck('id')->all())
        ->toEqualCanonicalizing([(string) $keep->id, (string) $hide->id]);
});

it('un-hides a share and restores it to the feed', function () {
    $place = Place::factory()->active()->atPoint(38.7, -9.1)->create();
    $share = publishedShare($place);
    $me = User::factory()->create();
    Sanctum::actingAs($me);

    $this->postJson('/api/v1/feed/hidden', ['share_id' => $share->id])->assertStatus(201);
    expect($this->getJson('/api/v1/feed')->json('data'))->toBeEmpty();

    $this->deleteJson("/api/v1/feed/hidden/{$share->id}")->assertOk();
    expect(collect($this->getJson('/api/v1/feed')->json('data'))->pluck('id')->all())
        ->toBe([(string) $share->id]);
});

it('is idempotent — hiding twice keeps one row and 200s the second time', function () {
    $place = Place::factory()->active()->atPoint(38.7, -9.1)->create();
    $share = publishedShare($place);
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/feed/hidden', ['share_id' => $share->id])->assertStatus(201);
    $this->postJson('/api/v1/feed/hidden', ['share_id' => $share->id])->assertStatus(200);
    expect(FeedDismissal::count())->toBe(1);
});

it('scopes dismissals per user — one user hiding does not affect another', function () {
    $place = Place::factory()->active()->atPoint(38.7, -9.1)->create();
    $share = publishedShare($place);

    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/feed/hidden', ['share_id' => $share->id])->assertStatus(201);

    Sanctum::actingAs(User::factory()->create());
    expect(collect($this->getJson('/api/v1/feed')->json('data'))->pluck('id')->all())
        ->toBe([(string) $share->id]);
});

it('rejects a guest dismiss (401) and a bad share_id (422)', function () {
    $this->postJson('/api/v1/feed/hidden', ['share_id' => 1])->assertStatus(401);

    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/feed/hidden', ['share_id' => 999999])->assertStatus(422);
    $this->postJson('/api/v1/feed/hidden', [])->assertStatus(422);
});

it('rejects hiding a non-published share (422 — not feed-visible)', function () {
    $unpublished = Share::factory()->create(['status' => ShareStatus::Review]);
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/feed/hidden', ['share_id' => $unpublished->id])->assertStatus(422);
});
