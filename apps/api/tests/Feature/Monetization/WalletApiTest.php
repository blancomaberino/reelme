<?php

use App\Enums\LedgerAccount;
use App\Models\Payout;
use App\Models\User;
use App\Services\Ledger\LedgerLine;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\FakeStripeConnect;
use App\Services\Payments\StripeConnect;

/**
 * The wallet endpoints (T-045, 03 §2.14).
 *
 * The organising property: **every figure is derived from the ledger and the
 * Connect status is read live.** A wallet that cached either would tell an
 * influencer they can cash out when Stripe has since asked for more documents,
 * or show a balance the books disagree with.
 */
function walletEarner(int $amount, bool $onboarded = true): User
{
    $user = User::factory()->create();

    app(LedgerService::class)->record('wallet:seed:'.$user->id, [
        LedgerLine::debit(LedgerAccount::RestaurantReceivable, $amount, 'EUR'),
        LedgerLine::credit(LedgerAccount::InfluencerEarnings, $amount, 'EUR', userId: $user->id),
    ]);

    if ($onboarded) {
        /** @var FakeStripeConnect $stripe */
        $stripe = app(StripeConnect::class);
        $stripe->enablePayouts($user);
    }

    return $user->refresh();
}

describe('GET /wallet', function () {
    it('derives the balance from the ledger and reads Connect live', function () {
        $user = walletEarner(7500);

        $res = $this->actingAs($user)->getJson('/api/v1/wallet')->assertOk();

        expect($res->json('data.available_minor'))->toBe(7500)
            ->and($res->json('data.currency'))->toBe('EUR')
            ->and($res->json('data.payout_threshold_minor'))->toBe(2500)
            ->and($res->json('data.can_request_payout'))->toBeTrue()
            ->and($res->json('data.connect.payouts_enabled'))->toBeTrue();
    });

    /*
     * `details_submitted` is not `payouts_enabled` (06 §4.3). The button must be
     * off, and the requirements must be visible so the influencer knows what to
     * do rather than just being blocked.
     */
    it('says a half-verified account cannot cash out yet, and why', function () {
        $user = walletEarner(7500, onboarded: false);
        /** @var FakeStripeConnect $stripe */
        $stripe = app(StripeConnect::class);
        $stripe->submitDetailsOnly($user);

        $res = $this->actingAs($user->refresh())->getJson('/api/v1/wallet')->assertOk();

        expect($res->json('data.can_request_payout'))->toBeFalse()
            ->and($res->json('data.connect.details_submitted'))->toBeTrue()
            ->and($res->json('data.connect.payouts_enabled'))->toBeFalse()
            ->and($res->json('data.connect.requirements_due'))->not->toBeEmpty();
    });

    it('reports a below-threshold balance without offering a payout', function () {
        $user = walletEarner(500);

        $res = $this->actingAs($user)->getJson('/api/v1/wallet')->assertOk();

        expect($res->json('data.available_minor'))->toBe(500)
            ->and($res->json('data.can_request_payout'))->toBeFalse();
    });

    it('requires authentication', function () {
        $this->getJson('/api/v1/wallet')->assertUnauthorized();
    });
});

describe('GET /wallet/ledger', function () {
    it('shows only the caller’s own earnings entries', function () {
        $mine = walletEarner(5000);
        walletEarner(9000);

        $rows = $this->actingAs($mine)->getJson('/api/v1/wallet/ledger')->assertOk()->json('data');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['amount_minor'])->toBe(5000)
            ->and($rows[0]['direction'])->toBe('credit');
    });

    it('cursor-paginates', function () {
        $user = User::factory()->create();
        foreach (range(1, 3) as $i) {
            app(LedgerService::class)->record("wallet:page:{$i}", [
                LedgerLine::debit(LedgerAccount::RestaurantReceivable, 100 * $i, 'EUR'),
                LedgerLine::credit(LedgerAccount::InfluencerEarnings, 100 * $i, 'EUR', userId: $user->id),
            ]);
        }

        $first = $this->actingAs($user)->getJson('/api/v1/wallet/ledger?limit=2')->assertOk();
        expect($first->json('data'))->toHaveCount(2)
            ->and($first->json('meta.pagination.next_cursor'))->not->toBeNull();

        $second = $this->actingAs($user)
            ->getJson('/api/v1/wallet/ledger?limit=2&cursor='.urlencode($first->json('meta.pagination.next_cursor')))
            ->assertOk();

        expect($second->json('data'))->toHaveCount(1);
    });
});

describe('POST /wallet/payouts', function () {
    it('cashes out and returns the payout', function () {
        $user = walletEarner(5000);

        $res = $this->actingAs($user)->postJson('/api/v1/wallet/payouts')->assertCreated();

        expect($res->json('data.amount_minor'))->toBe(5000)
            ->and($res->json('data.status'))->toBe('processing');

        // The balance drops immediately — the hold, not Stripe's answer.
        expect($this->actingAs($user)->getJson('/api/v1/wallet')->json('data.available_minor'))->toBe(0);
    });

    it('answers with a reason the wallet screen can act on', function () {
        $user = walletEarner(500);

        $this->actingAs($user)->postJson('/api/v1/wallet/payouts')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'payout_failed')
            ->assertJsonPath('error.details.reason', 'insufficient_balance')
            ->assertJsonPath('error.details.threshold_minor', 2500);
    });

    it('refuses before verification is complete', function () {
        $user = walletEarner(5000, onboarded: false);

        $this->actingAs($user)->postJson('/api/v1/wallet/payouts')
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'not_onboarded');
    });
});

describe('Connect onboarding', function () {
    it('mints a fresh link every time', function () {
        $user = User::factory()->create();

        $first = $this->actingAs($user)->postJson('/api/v1/wallet/connect/onboarding-link')->assertOk()->json('data.url');
        $second = $this->actingAs($user)->postJson('/api/v1/wallet/connect/onboarding-link')->assertOk()->json('data.url');

        // Links expire in minutes and are single-use — a cached one is a button
        // that fails, so "create or refresh" must genuinely refresh.
        expect($first)->not->toBe($second)
            ->and($user->fresh()->stripe_connect_account_id)->not->toBeNull();
    });

    it('reports the account status', function () {
        $user = walletEarner(0 + 100);

        $res = $this->actingAs($user)->getJson('/api/v1/wallet/connect/status')->assertOk();

        expect($res->json('data.payouts_enabled'))->toBeTrue()
            ->and($res->json('data.account_id'))->toStartWith('acct_');
    });

    it('reports no account before onboarding starts', function () {
        $res = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/wallet/connect/status')->assertOk();

        expect($res->json('data.account_id'))->toBeNull()
            ->and($res->json('data.payouts_enabled'))->toBeFalse();
    });
});

describe('GET /wallet/payouts', function () {
    it('lists only the caller’s payouts, newest first', function () {
        $user = User::factory()->create();
        Payout::factory()->paid()->create(['user_id' => $user->id, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30']);
        Payout::factory()->failed()->create(['user_id' => $user->id, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31']);
        Payout::factory()->create();

        $rows = $this->actingAs($user)->getJson('/api/v1/wallet/payouts')->assertOk()->json('data');

        expect($rows)->toHaveCount(2)
            ->and($rows[0]['status'])->toBe('failed')
            ->and($rows[0]['failure_reason'])->not->toBeNull()
            ->and($rows[1]['status'])->toBe('paid');
    });
});
