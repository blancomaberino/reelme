<?php

use App\Enums\LedgerAccount;
use App\Enums\PayoutStatus;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\StripeEvent;
use App\Models\User;
use App\Services\Ledger\LedgerLine;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\FakeStripeConnect;
use App\Services\Payments\PayoutService;
use App\Services\Payments\StripeConnect;

/**
 * The Stripe webhook endpoint (T-045, 03 §4.1).
 *
 * The organising property: **the signature is the only authentication.** The
 * route is public because Stripe carries no bearer token, so anything that gets
 * past `Stripe-Signature` can mark payouts paid. Every test here is either an
 * attempt to get past it, or a check that a legitimate event moves money exactly
 * once no matter how many times it is delivered.
 */
beforeEach(function () {
    config()->set('services.stripe.webhook_secret', WEBHOOK_SECRET);
});

// `WEBHOOK_SECRET`, `stripeSignature()`, `stripeEventPayload()` and
// `postWebhook()` live in tests/Helpers/StripeWebhookHelpers.php — the M4 loop
// test needs them too, and a helper declared in a test file only exists once
// that file has been compiled.

describe('signature verification', function () {
    it('rejects a missing signature', function () {
        postWebhook('transfer.paid', ['id' => 'tr_1'], signature: '')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_signature');

        expect(StripeEvent::query()->count())->toBe(0);
    });

    it('rejects a signature made with the wrong secret', function () {
        $payload = stripeEventPayload('transfer.paid', ['id' => 'tr_1']);

        postWebhook('transfer.paid', ['id' => 'tr_1'], signature: stripeSignature($payload, secret: 'whsec_wrong'))
            ->assertStatus(400);

        expect(StripeEvent::query()->count())->toBe(0);
    });

    /*
     * Stripe's own replay-protection window. A signature captured from an old
     * delivery must not be reusable indefinitely.
     */
    it('rejects a stale timestamp', function () {
        $payload = stripeEventPayload('transfer.paid', ['id' => 'tr_1']);

        postWebhook(
            'transfer.paid',
            ['id' => 'tr_1'],
            signature: stripeSignature($payload, timestamp: time() - 86_400),
        )->assertStatus(400);
    });

    /*
     * The signature covers the RAW body. If the endpoint decoded and re-encoded
     * the JSON before verifying, the bytes would differ and nothing would ever
     * validate — the classic way to get this wrong.
     */
    it('accepts a correctly signed event', function () {
        postWebhook('customer.created', ['id' => 'cus_1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'processed');

        expect(StripeEvent::query()->count())->toBe(1)
            ->and(StripeEvent::firstOrFail()->processed_at)->not->toBeNull();
    });

    it('refuses everything when no webhook secret is configured', function () {
        config()->set('services.stripe.webhook_secret', null);

        postWebhook('transfer.paid', ['id' => 'tr_1'])->assertStatus(503);

        // An endpoint that trusts anything because it cannot check is worse than
        // one that is switched off.
        expect(StripeEvent::query()->count())->toBe(0);
    });
});

describe('idempotency', function () {
    /*
     * Stripe redelivers on our 5xx, on timeouts, and sometimes unprompted. Every
     * handler here moves money, so a second delivery must be inert.
     */
    it('processes a redelivered event exactly once', function () {
        $user = User::factory()->create();
        $payout = Payout::factory()->processing('tr_dup')->create(['user_id' => $user->id]);

        postWebhook('transfer.paid', ['id' => 'tr_dup', 'metadata' => ['reelmap_payout_id' => (string) $payout->id]], 'evt_dup')
            ->assertOk()
            ->assertJsonPath('data.status', 'processed');

        postWebhook('transfer.paid', ['id' => 'tr_dup', 'metadata' => ['reelmap_payout_id' => (string) $payout->id]], 'evt_dup')
            ->assertOk()
            // 200, not an error: a non-2xx tells Stripe to retry, and retrying
            // something we deliberately ignored is a loop.
            ->assertJsonPath('data.status', 'duplicate');

        expect(StripeEvent::query()->count())->toBe(1);
    });

    /*
     * "Seen" is not "handled". A previous delivery that stored the row and then
     * threw leaves `processed_at` null — answering `duplicate` there would drop
     * the event permanently, because Stripe's retry keeps losing to the unique
     * index while nothing is ever applied.
     */
    it('replays an event that was stored but never processed', function () {
        $payout = Payout::factory()->processing('tr_stuck')->create();
        StripeEvent::factory()->create([
            'stripe_event_id' => 'evt_stuck',
            'type' => 'transfer.paid',
            'processed_at' => null,
        ]);

        postWebhook(
            'transfer.paid',
            ['id' => 'tr_stuck', 'metadata' => ['reelmap_payout_id' => (string) $payout->id]],
            'evt_stuck',
        )->assertOk()->assertJsonPath('data.status', 'processed');

        expect($payout->fresh()->status)->toBe(PayoutStatus::Paid)
            ->and(StripeEvent::firstOrFail()->processed_at)->not->toBeNull();
    });

    it('stores the event before acting on it', function () {
        postWebhook('transfer.paid', ['id' => 'tr_unknown'], 'evt_store')->assertOk();

        $stored = StripeEvent::firstOrFail();
        expect($stored->stripe_event_id)->toBe('evt_store')
            ->and($stored->type)->toBe('transfer.paid')
            // The whole payload survives: reconciling a disputed payout six
            // weeks later means reading what Stripe said, not our summary.
            ->and($stored->object()['id'])->toBe('tr_unknown');
    });
});

describe('transfer outcomes', function () {
    it('marks a payout paid on transfer.paid', function () {
        $payout = Payout::factory()->processing('tr_ok')->create();

        postWebhook('transfer.paid', ['id' => 'tr_ok', 'metadata' => ['reelmap_payout_id' => (string) $payout->id]])
            ->assertOk();

        $payout->refresh();
        expect($payout->status)->toBe(PayoutStatus::Paid)
            ->and($payout->paid_at)->not->toBeNull();
    });

    /*
     * `transfer.created` fires the moment the object exists — synchronously with
     * our own API call — so it says nothing about money having moved. Treating
     * it as settlement both lies and races `PayoutService::send()`, which is
     * still writing `processing` for the transfer it just created.
     */
    it('does not settle a payout on transfer.created', function () {
        $payout = Payout::factory()->create(['stripe_transfer_id' => 'tr_new']);

        postWebhook('transfer.created', ['id' => 'tr_new', 'metadata' => ['reelmap_payout_id' => (string) $payout->id]])
            ->assertOk();

        expect($payout->fresh()->status)->toBe(PayoutStatus::Pending)
            ->and($payout->fresh()->paid_at)->toBeNull();
    });

    /*
     * The race the guarded write exists for: a webhook settles the payout before
     * `send()` gets to write `processing`. A blind write there would REGRESS a
     * settled payout — the money moved and our record would say it is in flight.
     */
    it('never regresses a payout that a webhook already settled', function () {
        $payout = Payout::factory()->paid('tr_fast')->create();

        // What `send()` does after Stripe returns.
        Payout::query()->whereKey($payout->id)
            ->where('status', PayoutStatus::Pending)
            ->update(['status' => PayoutStatus::Processing]);

        expect($payout->fresh()->status)->toBe(PayoutStatus::Paid);
    });

    it('resolves a payout by transfer id when the metadata is absent', function () {
        $payout = Payout::factory()->processing('tr_bymeta')->create();

        postWebhook('transfer.paid', ['id' => 'tr_bymeta'])->assertOk();

        expect($payout->fresh()->status)->toBe(PayoutStatus::Paid);
    });

    /*
     * The failure path has to give the money back, not merely record a failure —
     * otherwise the influencer's balance stays spent on a transfer that never
     * happened.
     */
    it('releases the hold and restores the balance on a failure', function () {
        /** @var FakeStripeConnect $stripe */
        $stripe = app(StripeConnect::class);
        $user = User::factory()->create();
        $stripe->enablePayouts($user);

        // Earn, then request — so a real hold exists to release.
        app(LedgerService::class)->record('seed:wh', [
            LedgerLine::debit(LedgerAccount::RestaurantReceivable, 5000, 'EUR'),
            LedgerLine::credit(LedgerAccount::InfluencerEarnings, 5000, 'EUR', userId: $user->id),
        ]);
        $payout = app(PayoutService::class)->request($user->refresh());

        expect(app(PayoutService::class)->availableBalance($user))->toBe(0);

        postWebhook('transfer.failed', [
            'id' => $payout->stripe_transfer_id,
            'metadata' => ['reelmap_payout_id' => (string) $payout->id],
            'failure_message' => 'The destination account cannot receive transfers.',
        ])->assertOk();

        $payout->refresh();
        expect($payout->status)->toBe(PayoutStatus::Failed)
            ->and($payout->failure_reason)->toContain('cannot receive transfers')
            // Payable again — and the books still balance.
            ->and(app(PayoutService::class)->availableBalance($user))->toBe(5000);

        expect(app(LedgerService::class)->verifyInvariants()->isHealthy())->toBeTrue();
    });

    /*
     * Out-of-order delivery is normal. A `paid` for a payout we already failed
     * and released would pay money twice — once by releasing it to the balance,
     * once by calling it sent.
     */
    it('refuses to mark an already-failed payout as paid', function () {
        $payout = Payout::factory()->failed()->create(['stripe_transfer_id' => 'tr_late']);

        postWebhook('transfer.paid', ['id' => 'tr_late', 'metadata' => ['reelmap_payout_id' => (string) $payout->id]])
            ->assertOk();

        expect($payout->fresh()->status)->toBe(PayoutStatus::Failed);
    });

    it('refuses to fail an already-paid payout', function () {
        $payout = Payout::factory()->paid('tr_settled')->create();

        postWebhook('transfer.failed', ['id' => 'tr_settled', 'metadata' => ['reelmap_payout_id' => (string) $payout->id]])
            ->assertOk();

        expect($payout->fresh()->status)->toBe(PayoutStatus::Paid);
        // No compensating entries were written for a payout that settled.
        expect(LedgerEntry::query()->count())->toBe(0);
    });

    it('shrugs at an event for a transfer it does not know', function () {
        postWebhook('transfer.paid', ['id' => 'tr_never_seen'])->assertOk();

        expect(Payout::query()->count())->toBe(0);
    });
});

describe('account.updated', function () {
    it('stamps the onboarding timestamp once Stripe enables payouts', function () {
        /** @var FakeStripeConnect $stripe */
        $stripe = app(StripeConnect::class);
        $user = User::factory()->create();
        $stripe->enablePayouts($user);
        $user->refresh();

        expect($user->stripe_connect_onboarded_at)->toBeNull();

        postWebhook('account.updated', ['id' => $user->stripe_connect_account_id])->assertOk();

        expect($user->fresh()->stripe_connect_onboarded_at)->not->toBeNull();
    });

    /*
     * `details_submitted` is not `payouts_enabled` (06 §4.3). Stamping the
     * timestamp on the former would make a cached "onboarded" flag disagree with
     * the only flag that gates money.
     */
    it('does not stamp it when only details were submitted', function () {
        /** @var FakeStripeConnect $stripe */
        $stripe = app(StripeConnect::class);
        $user = User::factory()->create();
        $stripe->submitDetailsOnly($user);
        $user->refresh();

        postWebhook('account.updated', ['id' => $user->stripe_connect_account_id])->assertOk();

        expect($user->fresh()->stripe_connect_onboarded_at)->toBeNull();
    });

    it('ignores an account it has never seen', function () {
        postWebhook('account.updated', ['id' => 'acct_unknown'])->assertOk();
    });
});
