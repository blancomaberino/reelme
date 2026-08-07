<?php

use App\Models\Follow;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Moderation\BlockUsers;
use Illuminate\Database\QueryException;

/**
 * Blocking another account (T-054, IR-6 / Apple Guideline 1.2).
 *
 * A launch blocker for a UGC app, and the tests that matter are the SEAMS — a
 * `user_blocks` row that no read path consults has not blocked anything. Each
 * surface a blocked account's content can reach the blocker through is checked
 * here, in both directions, because "who blocked whom" must not change what is
 * visible.
 */

/** A published share by `$author`, visible on the feed. */
function publishedShareBy(User $author): Share
{
    $place = Place::factory()->active()->create();
    $share = Share::factory()->for($author)->create(['status' => 'published', 'published_at' => now()]);
    $source = PlaceSource::factory()->create([
        'place_id' => $place->id,
        'share_id' => $share->id,
        'published_at' => now(),
    ]);
    $share->forceFill(['published_place_source_id' => $source->id])->save();

    return $share;
}

it('hides the blocked account’s profile from the blocker', function () {
    $me = User::factory()->create();
    $them = User::factory()->create(['is_public' => true]);

    app(BlockUsers::class)->block($me, $them);

    // 404, not 403, and the same 404 a private profile gives. "You are blocked"
    // is itself information, and naming the account that blocked you is exactly
    // the nudge that starts a second account.
    $this->actingAs($me)->getJson("/api/v1/users/{$them->username}")->assertNotFound();
});

it('hides the blocker’s profile from the account they blocked', function () {
    $me = User::factory()->create(['is_public' => true]);
    $them = User::factory()->create();

    app(BlockUsers::class)->block($me, $them);

    // The direction that gets forgotten. A block that only stops the BLOCKER
    // from seeing content leaves them fully visible to the person they blocked
    // — which is the situation blocking exists to end.
    $this->actingAs($them)->getJson("/api/v1/users/{$me->username}")->assertNotFound();
});

it('keeps the profile visible to everybody else', function () {
    $me = User::factory()->create();
    $them = User::factory()->create(['is_public' => true]);
    $bystander = User::factory()->create();

    app(BlockUsers::class)->block($me, $them);

    // A block is between two people. Filtering on the blocked account rather
    // than on the PAIR would hide them from the whole product — a moderation
    // action, not a personal one.
    $this->actingAs($bystander)->getJson("/api/v1/users/{$them->username}")->assertOk();
});

it('drops the blocked account’s shares out of the feed', function () {
    $me = User::factory()->create();
    $them = User::factory()->create();
    $share = publishedShareBy($them);

    $this->actingAs($me)->getJson('/api/v1/feed?scope=global')
        ->assertOk()
        ->assertJsonFragment(['id' => (string) $share->id]);

    app(BlockUsers::class)->block($me, $them);

    // The GLOBAL scope, deliberately. An abusive account is not usually one the
    // blocker followed, so filtering only the `following` feed would leave the
    // content exactly where they see it.
    $response = $this->actingAs($me)->getJson('/api/v1/feed?scope=global')->assertOk();

    expect(collect($response->json('data'))->pluck('id'))->not->toContain((string) $share->id);
});

it('severs the follow edges in both directions, counters included', function () {
    $me = User::factory()->create();
    $them = User::factory()->create();

    $this->actingAs($me)->postJson('/api/v1/follows', ['followable_type' => 'user', 'followable_id' => $them->id])->assertCreated();
    $this->actingAs($them)->postJson('/api/v1/follows', ['followable_type' => 'user', 'followable_id' => $me->id])->assertCreated();

    app(BlockUsers::class)->block($me, $them);

    // The follow edge IS the subscription: leaving it in place means the
    // blocked account's new shares keep arriving in the blocker's following
    // feed and notifications.
    expect(Follow::query()->count())->toBe(0)
        // And the DENORMALIZED counters, which are what a person actually sees.
        // A bulk delete that skips them leaves a profile reading "1 follower"
        // above an empty list — a visible bug, not an internal one.
        ->and($me->fresh()->followers_count)->toBe(0)
        ->and($me->fresh()->following_count)->toBe(0)
        ->and($them->fresh()->followers_count)->toBe(0)
        ->and($them->fresh()->following_count)->toBe(0);
});

it('does not restore the follows when the block is lifted', function () {
    $me = User::factory()->create();
    $them = User::factory()->create();
    $this->actingAs($me)->postJson('/api/v1/follows', ['followable_type' => 'user', 'followable_id' => $them->id])->assertCreated();

    $blocks = app(BlockUsers::class);
    $blocks->block($me, $them);
    $blocks->unblock($me, $them);

    // They were severed, not paused. Silently re-subscribing somebody to an
    // account they blocked is not a decision the app gets to make for them.
    expect(Follow::query()->count())->toBe(0);
    $this->actingAs($me)->getJson("/api/v1/users/{$them->username}")->assertOk();
});

it('blocks and unblocks over HTTP, and lists who is blocked', function () {
    $me = User::factory()->create();
    $them = User::factory()->create(['username' => 'noisy']);

    $this->actingAs($me)->postJson('/api/v1/me/blocks/noisy')->assertNoContent();

    // The list is what makes a block REVERSIBLE: the blocked profile is a 404
    // for the blocker, so settings is the only place they can find it again.
    $this->actingAs($me)->getJson('/api/v1/me/blocks')
        ->assertOk()
        ->assertJsonPath('data.0.username', 'noisy');

    $this->actingAs($me)->deleteJson('/api/v1/me/blocks/noisy')->assertNoContent();
    $this->actingAs($me)->getJson('/api/v1/me/blocks')->assertOk()->assertJsonCount(0, 'data');
});

it('treats a repeated block as success, not a conflict', function () {
    $me = User::factory()->create();
    $them = User::factory()->create(['username' => 'noisy']);

    $this->actingAs($me)->postJson('/api/v1/me/blocks/noisy')->assertNoContent();

    // A stale profile screen or a double tap. The client cannot always know the
    // current state, and "you already blocked them" is a worse answer than
    // quietly agreeing.
    $this->actingAs($me)->postJson('/api/v1/me/blocks/noisy')->assertNoContent();

    expect(UserBlock::query()->count())->toBe(1);
});

it('refuses a self-block', function () {
    $me = User::factory()->create(['username' => 'me']);

    // Not a nicety: a self-block empties your own feed and 404s your own
    // profile, and the bug would read as data loss.
    $this->actingAs($me)->postJson('/api/v1/me/blocks/me')->assertStatus(422);

    expect(UserBlock::query()->count())->toBe(0);
});

it('will not let the database hold a self-block either', function () {
    $me = User::factory()->create();

    // The FormRequest guard above is one line away from being refactored out.
    // The CHECK constraint is what makes it impossible.
    expect(fn () => UserBlock::create(['blocker_id' => $me->id, 'blocked_id' => $me->id]))
        ->toThrow(QueryException::class);
});

it('leaves the blocked account’s places on the map', function () {
    $me = User::factory()->create();
    $them = User::factory()->create();
    $share = publishedShareBy($them);
    $place = $share->publishedPlaceSource->place;

    app(BlockUsers::class)->block($me, $them);

    // A place is COMMUNITY data with many contributing sources. Dropping a
    // restaurant off the map because one blocked account also shared it would
    // punish the blocker, not the person they blocked. Their attribution
    // disappears from the feed; the pin stays. (Same reasoning as T-049's
    // refusal to take down a `source_post` shared between users.)
    $this->actingAs($me)
        ->getJson("/api/v1/places/{$place->slug}")
        ->assertOk()
        ->assertJsonPath('data.id', (string) $place->id);
});

it('drops the blocked account out of a place’s attribution list', function () {
    $me = User::factory()->create();
    $them = User::factory()->create();
    $share = publishedShareBy($them);
    $place = $share->publishedPlaceSource->place;

    $this->actingAs($me)->getJson("/api/v1/places/{$place->id}/sources")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    app(BlockUsers::class)->block($me, $them);

    // The place survives (test above) but their NAME under it does not. Keeping
    // the pin while still crediting the account by name would leave the blocker
    // reading the handle they blocked, which is most of what blocking is for.
    $this->actingAs($me)->getJson("/api/v1/places/{$place->id}/sources")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('still shows that attribution to everybody else', function () {
    $me = User::factory()->create();
    $them = User::factory()->create();
    $bystander = User::factory()->create();
    $share = publishedShareBy($them);
    $place = $share->publishedPlaceSource->place;

    app(BlockUsers::class)->block($me, $them);

    // Filtering on the blocked account rather than on the PAIR would erase
    // their contributions from the whole product — a moderation action.
    $this->actingAs($bystander)->getJson("/api/v1/places/{$place->id}/sources")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('is invisible to a guest, who has blocked nobody', function () {
    $them = User::factory()->create(['is_public' => true]);
    $me = User::factory()->create();
    app(BlockUsers::class)->block($me, $them);

    // `invisibleTo(null)` must not go looking, and a signed-out viewer must not
    // inherit somebody else's blocks.
    expect(app(BlockUsers::class)->invisibleTo(null))->toBe([])
        ->and(app(BlockUsers::class)->betweenExists(null, $them->id))->toBeFalse();

    $this->getJson("/api/v1/users/{$them->username}")->assertOk();
});
