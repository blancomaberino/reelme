<?php

use App\Enums\ShareStatus;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\User;
use App\Notifications\Channels\ExpoChannel;
use App\Notifications\ShareFailed;
use App\Notifications\SharePublished;
use App\Notifications\ShareReviewNeeded;
use Illuminate\Support\Facades\Notification;

/** A share owned by $user, sitting in $status, ready to transition. */
function shareIn(ShareStatus $status, User $user): Share
{
    return Share::factory()->for($user)->create(['status' => $status]);
}

it('notifies the sharer with a place deep-link when a share publishes', function () {
    Notification::fake();
    $user = User::factory()->create();
    $place = Place::factory()->create(['name' => 'Café Tortoni']);
    $share = shareIn(ShareStatus::Analyzing, $user);
    $source = PlaceSource::factory()->create([
        'place_id' => $place->id,
        'share_id' => $share->id,
        'source_post_id' => $share->source_post_id,
        'published_at' => now(),
    ]);
    // published_place_source_id is not fillable (status-machine field) — assign directly.
    $share->published_place_source_id = $source->id;
    $share->save();

    expect($share->transitionTo(ShareStatus::Published))->toBeTrue();

    Notification::assertSentTo($user, SharePublished::class, function (SharePublished $n) use ($user, $place) {
        $expo = $n->toExpo($user);

        return $expo['data']['type'] === 'share.published'
            && $expo['data']['url'] === '/place/'.$place->slug
            && $n->toDatabase($user)['url'] === '/place/'.$place->slug
            // Both the DB (notification center) and Expo channels fire.
            && $n->via($user) === ['database', ExpoChannel::class];
    });
});

it('notifies with the review deep-link when a share enters review', function () {
    Notification::fake();
    $user = User::factory()->create();
    $share = shareIn(ShareStatus::Analyzing, $user);

    expect($share->transitionTo(ShareStatus::Review))->toBeTrue();

    Notification::assertSentTo($user, ShareReviewNeeded::class, function (ShareReviewNeeded $n) use ($user, $share) {
        $expo = $n->toExpo($user);

        return $expo['data']['type'] === 'share.review_needed'
            && $expo['data']['url'] === '/shares/'.$share->id.'/review';
    });
});

it('notifies with the status deep-link when a share fails', function () {
    Notification::fake();
    $user = User::factory()->create();
    $share = shareIn(ShareStatus::Pending, $user);

    expect($share->transitionTo(ShareStatus::Failed, 'fetch_unavailable'))->toBeTrue();

    Notification::assertSentTo($user, ShareFailed::class, function (ShareFailed $n) use ($user, $share) {
        $expo = $n->toExpo($user);

        return $expo['data']['type'] === 'share.failed'
            && $expo['data']['url'] === '/shares/'.$share->id.'/status';
    });
});

it('stays silent on transitions that are not published/review/failed', function () {
    Notification::fake();
    $user = User::factory()->create();
    $share = shareIn(ShareStatus::Pending, $user);

    expect($share->transitionTo(ShareStatus::Fetching))->toBeTrue();

    Notification::assertNothingSent();
});

it('does not re-notify on a moderation forceResetStatus (no ShareStatusChanged)', function () {
    Notification::fake();
    $user = User::factory()->create();
    $share = shareIn(ShareStatus::Published, $user);

    // Admin reprocess resets status without firing the event — no push.
    $share->forceResetStatus(ShareStatus::Failed, 'admin_removed');

    Notification::assertNothingSent();
});
