<?php

use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Models\User;
use App\Notifications\Channels\ExpoChannel;
use App\Notifications\PayoutPaid;
use App\Services\Payments\PayoutService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Telling a user their money actually moved (T-045).
 *
 * `wallet.payout` sat in the mobile type union and icon map from T-040 onward
 * with nothing on the server ever emitting it: a payout went
 * `pending → processing → paid` in silence, so the only way to find out was to
 * open the wallet and notice the number had changed.
 *
 * The exactly-once property is the part worth pinning. Stripe redelivers
 * webhooks freely, and `markPaid` is the idempotency point — two "we sent you
 * €25" notifications for one transfer read as two payouts.
 */
it('notifies the influencer when a transfer settles', function () {
    Notification::fake();

    $user = User::factory()->create();
    $payout = Payout::factory()->processing()->create(['user_id' => $user->id, 'amount' => 2500, 'currency' => 'EUR']);

    app(PayoutService::class)->markPaid($payout);

    Notification::assertSentTo($user, PayoutPaid::class);
    expect($payout->fresh()->status)->toBe(PayoutStatus::Paid);
});

it('notifies once even when Stripe redelivers the webhook', function () {
    Notification::fake();

    $user = User::factory()->create();
    $payout = Payout::factory()->processing()->create(['user_id' => $user->id]);

    $service = app(PayoutService::class);
    $service->markPaid($payout);
    // Same row, replayed — the early return on an already-paid payout is what
    // has to stop the second notification.
    $service->markPaid($payout->fresh());

    Notification::assertSentToTimes($user, PayoutPaid::class, 1);
});

it('stays silent for a paid event that arrives after a failure', function () {
    Notification::fake();
    Log::spy();

    $user = User::factory()->create();
    $payout = Payout::factory()->create([
        'user_id' => $user->id,
        'status' => PayoutStatus::Failed,
        'failure_reason' => 'card_declined',
    ]);

    app(PayoutService::class)->markPaid($payout);

    // The release and the transfer disagree — a human has to look. Promising
    // the user money we already returned to their balance is the worst
    // possible response.
    Notification::assertNothingSentTo($user);
    expect($payout->fresh()->status)->toBe(PayoutStatus::Failed);
});

it('reaches both the center and the phone', function () {
    $via = (new PayoutPaid(Payout::factory()->create()))->via(User::factory()->create());

    expect($via)->toContain('database')->and($via)->toContain(ExpoChannel::class);
});

it('carries a wallet deep-link and machine-readable money', function () {
    $payout = Payout::factory()->create(['amount' => 4500, 'currency' => 'EUR']);

    $payload = (new PayoutPaid($payout))->toDatabase(User::factory()->create());

    expect($payload['type'])->toBe('wallet.payout')
        ->and($payload['url'])->toBe('/wallet')
        // Minor units, so the client can format with the user's own currency
        // setting instead of re-parsing a formatted string.
        ->and($payload['amount_minor'])->toBe(4500)
        ->and($payload['currency'])->toBe('EUR')
        // Byte-for-byte what the mobile `formatMoney()` produces, so the push
        // banner and the center row show one amount, not two spellings of it.
        ->and($payload['body'])->toContain('€45.00');
});
