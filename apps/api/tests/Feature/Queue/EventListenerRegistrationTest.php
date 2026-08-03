<?php

use App\Events\RedemptionVerified;
use App\Events\ShareStatusChanged;
use App\Listeners\NotifyOnRedemptionVerified;
use App\Listeners\SendShareStatusNotification;
use Illuminate\Support\Facades\Event;

/**
 * Every listener runs ONCE per event.
 *
 * Found while adding the T-043 redemption listener: Laravel discovers listeners
 * in `app/Listeners` automatically from their `handle()` type hint, so an
 * additional `Event::listen()` in a service provider registers the same class a
 * SECOND time and it fires twice. That was already live — every share status
 * change was sending two notifications — and it is much worse on the redemption
 * path, where T-044 will hang fee posting off `RedemptionVerified` and a
 * duplicate becomes a restaurant billed twice for one visit.
 *
 * Duplicate registration is invisible in normal use (two identical
 * notifications look like a resend, two ledger entries look like two visits),
 * which is exactly why it needs a test rather than a comment.
 */
it('registers each listener exactly once', function (string $event, string $listener) {
    $registered = collect(Event::getRawListeners()[$event] ?? [])
        ->map(fn ($l) => is_string($l) ? explode('@', $l)[0] : $l)
        ->filter(fn ($l) => $l === $listener);

    expect($registered)->toHaveCount(1);
})->with([
    [ShareStatusChanged::class, SendShareStatusNotification::class],
    [RedemptionVerified::class, NotifyOnRedemptionVerified::class],
]);

/**
 * The guard that generalises the rule: if a future listener is BOTH discovered
 * and hand-registered, this fails without anyone having to think of it.
 */
it('never registers the same listener twice for any event', function () {
    $duplicates = collect(Event::getRawListeners())
        ->map(fn (array $listeners) => collect($listeners)
            ->filter(fn ($l) => is_string($l))
            ->map(fn (string $l) => explode('@', $l)[0])
            ->countBy()
            ->filter(fn (int $count) => $count > 1)
            ->keys()
            ->all())
        ->filter(fn (array $dupes) => $dupes !== []);

    expect($duplicates->all())->toBe([]);
});
