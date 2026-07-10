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
