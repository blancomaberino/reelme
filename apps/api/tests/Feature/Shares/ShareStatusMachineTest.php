<?php

use App\Enums\ShareStatus;
use App\Events\ShareStatusChanged;
use App\Exceptions\InvalidShareTransition;
use App\Models\Share;
use Illuminate\Support\Facades\Event;

it('permits every legal transition and rejects every illegal one', function () {
    foreach (ShareStatus::cases() as $from) {
        foreach (ShareStatus::cases() as $to) {
            $legal = in_array($to, $from->transitions(), true);
            $share = Share::factory()->create(['status' => $from]);

            if ($legal) {
                expect($share->transitionTo($to, 'fetch_unavailable'))->toBeTrue()
                    ->and($share->fresh()->status)->toBe($to);
            } else {
                expect(fn () => $share->transitionTo($to))
                    ->toThrow(InvalidShareTransition::class);
            }
        }
    }
});

it('rejects known-illegal transitions (explicit edges, independent of the map)', function () {
    // Hard-coded illegal edges so this does NOT just mirror transitions() — a
    // wrong edit to the map can't silently make this pass.
    $illegal = [
        [ShareStatus::Pending, ShareStatus::Published],
        [ShareStatus::Pending, ShareStatus::Analyzing],
        [ShareStatus::Failed, ShareStatus::Published],
        [ShareStatus::Published, ShareStatus::Fetching],
        [ShareStatus::Rejected, ShareStatus::Fetching],
    ];

    foreach ($illegal as [$from, $to]) {
        $share = Share::factory()->create(['status' => $from]);
        expect(fn () => $share->transitionTo($to))->toThrow(InvalidShareTransition::class);
    }
});

it('clears a stale failure_reason on a reason-less transition', function () {
    // A share that failed, then retries: the retry transition passes no reason, so
    // the stale code must be cleared — otherwise it later resurfaces to the client.
    $share = Share::factory()->create([
        'status' => ShareStatus::Failed,
        'failure_reason' => 'fetch_unavailable',
    ]);

    expect($share->transitionTo(ShareStatus::Fetching))->toBeTrue()
        ->and($share->fresh()->failure_reason)->toBeNull()
        ->and($share->failure_reason)->toBeNull(); // in-memory too, not just the row
});

it('allows discard (→ rejected) from every non-terminal state', function () {
    // Pins the product rule directly (not the transition map): a user can always
    // discard an in-flight share. Regression guard for the DELETE /shares/{id}
    // 500 that fired from pending/fetching/analyzing/failed.
    $nonTerminal = array_filter(ShareStatus::cases(), fn (ShareStatus $s): bool => ! $s->isTerminal());
    expect($nonTerminal)->not->toBeEmpty();

    foreach ($nonTerminal as $status) {
        $share = Share::factory()->create(['status' => $status]);

        expect($share->transitionTo(ShareStatus::Rejected, 'user_discarded'))->toBeTrue()
            ->and($share->fresh()->status)->toBe(ShareStatus::Rejected);
    }
});

it('returns false when another worker already moved the row (optimistic guard)', function () {
    $share = Share::factory()->create(['status' => ShareStatus::Pending]);

    // Simulate a concurrent transition; our in-memory model still thinks pending.
    Share::whereKey($share->id)->update(['status' => ShareStatus::Fetching->value]);

    expect($share->transitionTo(ShareStatus::Fetching))->toBeFalse();
});

it('fires ShareStatusChanged on a successful transition', function () {
    Event::fake([ShareStatusChanged::class]);

    $share = Share::factory()->create(['status' => ShareStatus::Pending]);
    $share->transitionTo(ShareStatus::Fetching);

    Event::assertDispatched(ShareStatusChanged::class, fn (ShareStatusChanged $e) => $e->from === ShareStatus::Pending && $e->to === ShareStatus::Fetching);
});

it('sets published_at when transitioning to published', function () {
    $share = Share::factory()->create(['status' => ShareStatus::Analyzing]);

    $share->transitionTo(ShareStatus::Published);

    expect($share->fresh()->published_at)->not->toBeNull();
});
