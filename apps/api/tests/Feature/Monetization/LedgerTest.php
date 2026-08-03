<?php

use App\Enums\LedgerAccount;
use App\Enums\LedgerDirection;
use App\Exceptions\UnbalancedTransaction;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Ledger\LedgerLine;
use App\Services\Ledger\LedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The double-entry ledger (T-044, 02 §3.15, 06 §4.2).
 *
 * The organising property: **the books always balance, and history is never
 * rewritten.** Everything downstream — a restaurant's invoice, an influencer's
 * payout, the platform's margin — is a SUM over these rows, so a ledger that can
 * be half-written, edited, or double-posted does not have a reporting bug, it
 * has no meaning at all.
 */
function ledger(): LedgerService
{
    return app(LedgerService::class);
}

/** @return list<LedgerLine> */
function balancedLines(int $amount = 300, string $currency = 'EUR'): array
{
    return [
        LedgerLine::debit(LedgerAccount::RestaurantReceivable, $amount, $currency),
        LedgerLine::credit(LedgerAccount::PlatformRevenue, $amount, $currency),
    ];
}

describe('recording a transaction', function () {
    it('writes every line under one transaction_uuid', function () {
        $tx = ledger()->record('test:1:capture', balancedLines());

        expect($tx->entries)->toHaveCount(2)
            ->and($tx->replayed)->toBeFalse()
            ->and($tx->entries->pluck('transaction_uuid')->unique())->toHaveCount(1)
            ->and($tx->total('EUR'))->toBe(300);

        // `{prefix}:{n}` — the index makes each LINE unique while the prefix
        // identifies the transaction.
        expect($tx->entries->pluck('idempotency_key')->all())
            ->toBe(['test:1:capture:0', 'test:1:capture:1']);
    });

    /*
     * The check runs BEFORE any insert, so an imbalance cannot land even
     * partially. A half-written transaction is worse than a rejected one: it
     * balances nothing and no sum-based audit would flag the missing side.
     */
    it('refuses an imbalance and writes nothing at all', function () {
        $lines = [
            LedgerLine::debit(LedgerAccount::RestaurantReceivable, 300, 'EUR'),
            LedgerLine::credit(LedgerAccount::PlatformRevenue, 250, 'EUR'),
        ];

        expect(fn () => ledger()->record('test:bad', $lines))->toThrow(UnbalancedTransaction::class);
        expect(LedgerEntry::query()->count())->toBe(0);
    });

    it('balances per CURRENCY, not across currencies', function () {
        // 300 EUR debit against 300 USD credit is not a balanced transaction; it
        // is two unbalanced ones that happen to share a number.
        $lines = [
            LedgerLine::debit(LedgerAccount::RestaurantReceivable, 300, 'EUR'),
            LedgerLine::credit(LedgerAccount::PlatformRevenue, 300, 'USD'),
        ];

        expect(fn () => ledger()->record('test:mixed', $lines))->toThrow(UnbalancedTransaction::class);
        expect(LedgerEntry::query()->count())->toBe(0);
    });

    it('accepts a multi-currency transaction that balances on both sides', function () {
        $tx = ledger()->record('test:multi', [
            LedgerLine::debit(LedgerAccount::RestaurantReceivable, 300, 'EUR'),
            LedgerLine::credit(LedgerAccount::PlatformRevenue, 300, 'EUR'),
            LedgerLine::debit(LedgerAccount::RestaurantReceivable, 400, 'USD'),
            LedgerLine::credit(LedgerAccount::PlatformRevenue, 400, 'USD'),
        ]);

        expect($tx->total('EUR'))->toBe(300)->and($tx->total('USD'))->toBe(400);
    });

    it('refuses a single-sided transaction', function () {
        expect(fn () => ledger()->record('test:one', [
            LedgerLine::debit(LedgerAccount::RestaurantReceivable, 300, 'EUR'),
        ]))->toThrow(UnbalancedTransaction::class);
    });

    /*
     * Sign lives in `direction`, never in the number. A negative amount would
     * make one row read as a debit or a credit depending on the caller — and
     * would silently satisfy the balance check.
     */
    it('refuses a negative or zero amount', function (int $amount) {
        expect(fn () => ledger()->record('test:neg', [
            new LedgerLine(LedgerAccount::RestaurantReceivable, LedgerDirection::Debit, $amount, 'EUR'),
            new LedgerLine(LedgerAccount::PlatformRevenue, LedgerDirection::Credit, $amount, 'EUR'),
        ]))->toThrow(UnbalancedTransaction::class);
    })->with([0, -300]);

    it('exposes the subledger owner as a relation', function () {
        $earner = User::factory()->create();

        $tx = ledger()->record('test:owner', [
            LedgerLine::debit(LedgerAccount::RestaurantReceivable, 150, 'EUR'),
            LedgerLine::credit(LedgerAccount::InfluencerEarnings, 150, 'EUR', userId: $earner->id),
        ]);

        expect($tx->entries->last()->user->is($earner))->toBeTrue()
            // The debit side belongs to no party — that is not the same as
            // belonging to nobody in particular.
            ->and($tx->entries->first()->user)->toBeNull();
    });

    it('records the reference as a short morph name, not a class name', function () {
        $user = User::factory()->create();

        $tx = ledger()->record('test:ref', balancedLines(), $user);

        expect($tx->entries->first()->reference_type)->toBe('user')
            ->and($tx->entries->first()->reference_id)->toBe($user->id);
    });
});

describe('idempotency', function () {
    /*
     * The posting path runs inside the redemption verify transaction, which
     * clients and queues retry. Replaying must be a quiet no-op returning the
     * ORIGINAL — anything else is a second fee for one visit.
     */
    it('returns the original transaction instead of posting a second', function () {
        $first = ledger()->record('test:dup:capture', balancedLines());
        $second = ledger()->record('test:dup:capture', balancedLines());

        expect($second->uuid)->toBe($first->uuid)
            ->and($second->replayed)->toBeTrue()
            ->and(LedgerEntry::query()->count())->toBe(2);
    });

    /*
     * `_` and `%` are LIKE wildcards. An unescaped prefix containing one would
     * match a DIFFERENT posting's keys — and this lookup's answer is "has this
     * already been paid", so a false match suppresses a real fee.
     */
    it('does not treat an underscore in a key as a wildcard', function () {
        ledger()->record('test:a_b:capture', balancedLines());

        // Would match under an unescaped LIKE (`_` matches any character).
        expect(ledger()->findByPrefix('test:axb:capture'))->toBeNull()
            ->and(ledger()->findByPrefix('test:a_b:capture'))->not->toBeNull();
    });

    it('does not confuse an id that is a prefix of another', function () {
        ledger()->record('redemption:1:capture', balancedLines());

        // `redemption:1:capture:%` must not match `redemption:12:...`.
        expect(ledger()->findByPrefix('redemption:12:capture'))->toBeNull();
    });

    it('keeps distinct prefixes distinct', function () {
        ledger()->record('test:a:capture', balancedLines());
        ledger()->record('test:b:capture', balancedLines());

        expect(LedgerEntry::query()->count())->toBe(4)
            ->and(LedgerEntry::query()->distinct()->count('transaction_uuid'))->toBe(2);
    });

    it('enforces the key at the database level, not only in the service', function () {
        ledger()->record('test:unique', balancedLines());

        expect(fn () => DB::transaction(fn () => LedgerEntry::factory()->create([
            'idempotency_key' => 'test:unique:0',
        ])))->toThrow(QueryException::class);
    });
});

describe('append-only', function () {
    it('refuses an update through the model', function () {
        $tx = ledger()->record('test:immutable', balancedLines());
        $entry = $tx->entries->first();

        expect(fn () => $entry->forceFill(['amount' => 1])->save())->toThrow(LogicException::class);
        expect($entry->fresh()->amount)->toBe(300);
    });

    it('refuses a delete through the model', function () {
        $tx = ledger()->record('test:immutable2', balancedLines());

        expect(fn () => $tx->entries->first()->delete())->toThrow(LogicException::class);
        expect(LedgerEntry::query()->count())->toBe(2);
    });

    /*
     * The model guard stops application code; the trigger stops everything else
     * — a tinker session, a migration, psql. For a table whose entire value is
     * that history cannot be rewritten, "we promise not to" is not the same
     * guarantee as "the database refuses".
     */
    it('refuses a raw UPDATE at the database level', function () {
        $tx = ledger()->record('test:trigger', balancedLines());

        expect(fn () => DB::transaction(fn () => DB::table('ledger_entries')
            ->where('transaction_uuid', $tx->uuid)
            ->update(['amount' => 1])))->toThrow(QueryException::class);

        // Cast: Postgres returns a bigint sum as a string.
        expect((int) LedgerEntry::query()->sum('amount'))->toBe(600);
    });

    it('refuses a raw DELETE at the database level', function () {
        $tx = ledger()->record('test:trigger2', balancedLines());

        expect(fn () => DB::transaction(fn () => DB::table('ledger_entries')
            ->where('transaction_uuid', $tx->uuid)
            ->delete()))->toThrow(QueryException::class);

        expect(LedgerEntry::query()->count())->toBe(2);
    });
});

describe('balances', function () {
    /*
     * Signed against each account's NORMAL direction, so a positive number
     * always means "more of what this account is for". Without that convention
     * every caller would have to remember which way round a given account runs,
     * and one that got it backwards would report a debt as a credit.
     */
    it('signs a balance against the account’s normal direction', function () {
        ledger()->record('test:bal', balancedLines(300));

        // Receivable is an ASSET: debits increase it.
        expect(ledger()->balance(LedgerAccount::RestaurantReceivable))->toBe(300)
            // Revenue is a CREDIT account: credits increase it.
            ->and(ledger()->balance(LedgerAccount::PlatformRevenue))->toBe(300);
    });

    it('nets a reversal back to zero', function () {
        $tx = ledger()->record('test:rev:capture', balancedLines(300));
        ledger()->reverse($tx, 'test:rev:void');

        expect(ledger()->balance(LedgerAccount::RestaurantReceivable))->toBe(0)
            ->and(ledger()->balance(LedgerAccount::PlatformRevenue))->toBe(0)
            // Both sets survive — the correction is additive, so the books still
            // show that a fee was charged AND that it was reversed.
            ->and(LedgerEntry::query()->count())->toBe(4);
    });

    it('keeps each user’s subledger separate', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        ledger()->record('test:alice', [
            LedgerLine::debit(LedgerAccount::RestaurantReceivable, 150, 'EUR'),
            LedgerLine::credit(LedgerAccount::InfluencerEarnings, 150, 'EUR', userId: $alice->id),
        ]);
        ledger()->record('test:bob', [
            LedgerLine::debit(LedgerAccount::RestaurantReceivable, 90, 'EUR'),
            LedgerLine::credit(LedgerAccount::InfluencerEarnings, 90, 'EUR', userId: $bob->id),
        ]);

        expect(ledger()->balance(LedgerAccount::InfluencerEarnings, $alice))->toBe(150)
            ->and(ledger()->balance(LedgerAccount::InfluencerEarnings, $bob))->toBe(90)
            // A null user is NOT "all users" — it is the escrow rows.
            ->and(ledger()->balance(LedgerAccount::InfluencerEarnings))->toBe(0);
    });

    it('lists every party’s balance in one query for the payout run', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        ledger()->record('test:pa', [
            LedgerLine::debit(LedgerAccount::RestaurantReceivable, 150, 'EUR'),
            LedgerLine::credit(LedgerAccount::InfluencerEarnings, 150, 'EUR', userId: $alice->id),
        ]);
        ledger()->record('test:pb', [
            LedgerLine::debit(LedgerAccount::RestaurantReceivable, 90, 'EUR'),
            LedgerLine::credit(LedgerAccount::InfluencerEarnings, 90, 'EUR', userId: $bob->id),
        ]);
        // Escrow must NOT appear — a payout run cannot sweep money that belongs
        // to nobody yet.
        ledger()->record('test:escrow', [
            LedgerLine::debit(LedgerAccount::RestaurantReceivable, 70, 'EUR'),
            LedgerLine::credit(LedgerAccount::InfluencerEarnings, 70, 'EUR'),
        ]);

        expect(ledger()->balancesByUser(LedgerAccount::InfluencerEarnings))
            ->toBe([$alice->id => 150, $bob->id => 90]);
    });

    it('separates currencies', function () {
        ledger()->record('test:eur', balancedLines(300, 'EUR'));
        ledger()->record('test:usd', balancedLines(400, 'USD'));

        expect(ledger()->balance(LedgerAccount::PlatformRevenue, null, 'EUR'))->toBe(300)
            ->and(ledger()->balance(LedgerAccount::PlatformRevenue, null, 'USD'))->toBe(400);
    });
});

describe('the invariant checker', function () {
    it('passes on a healthy ledger', function () {
        ledger()->record('test:ok1', balancedLines());
        ledger()->record('test:ok2', balancedLines(1200));

        $report = ledger()->verifyInvariants();

        expect($report->isHealthy())->toBeTrue()
            ->and($report->checked)->toBe(2)
            ->and($report->summary())->toContain('healthy');
    });

    /*
     * The service cannot produce this — which is exactly why the checker exists.
     * A raw insert stands in for the things that could: a migration, a tinker
     * session, a future code path that bypasses the service.
     */
    it('catches an imbalance introduced outside the service', function () {
        $uuid = (string) Str::uuid();
        LedgerEntry::factory()->create(['transaction_uuid' => $uuid, 'direction' => LedgerDirection::Debit, 'amount' => 300, 'idempotency_key' => 'raw:0']);
        LedgerEntry::factory()->create(['transaction_uuid' => $uuid, 'direction' => LedgerDirection::Credit, 'amount' => 250, 'idempotency_key' => 'raw:1']);

        $report = ledger()->verifyInvariants();

        expect($report->isHealthy())->toBeFalse()
            ->and($report->unbalanced)->toHaveCount(1)
            ->and($report->unbalanced[0]['debits'])->toBe(300)
            ->and($report->unbalanced[0]['credits'])->toBe(250)
            ->and($report->summary())->toContain('VIOLATED');
    });

    /*
     * A one-row transaction is a HALF-WRITTEN one, and a sum-based check alone
     * would miss it: with nothing to compare against, "balanced" is vacuous.
     */
    it('catches a single-entry transaction a sum check would miss', function () {
        LedgerEntry::factory()->create(['idempotency_key' => 'orphan:0']);

        $report = ledger()->verifyInvariants();

        expect($report->isHealthy())->toBeFalse()
            ->and($report->singleEntryTransactions)->toHaveCount(1);
    });

    it('exits non-zero from the command so a failed run is visible', function () {
        LedgerEntry::factory()->create(['idempotency_key' => 'orphan2:0']);

        $this->artisan('reelmap:ledger:verify')->assertFailed();
    });

    it('exits zero on a healthy ledger', function () {
        ledger()->record('test:healthy', balancedLines());

        $this->artisan('reelmap:ledger:verify')->assertSuccessful();
    });
});

describe('the global invariant', function () {
    /*
     * The property that has to hold no matter what sequence of postings happened:
     * across the WHOLE table, debits equal credits per currency. Everything else
     * in this file tests one rule; this tests that the rules compose.
     */
    it('keeps total debits equal to total credits after many mixed postings', function () {
        $users = User::factory()->count(3)->create();

        for ($i = 0; $i < 25; $i++) {
            $amount = 100 + ($i * 37) % 400;
            $share = intdiv($amount, 2);
            $currency = $i % 5 === 0 ? 'USD' : 'EUR';

            $tx = ledger()->record("prop:{$i}:capture", array_values(array_filter([
                LedgerLine::debit(LedgerAccount::RestaurantReceivable, $amount, $currency),
                LedgerLine::credit(LedgerAccount::PlatformRevenue, $amount - $share, $currency),
                $share > 0 ? LedgerLine::credit(
                    LedgerAccount::InfluencerEarnings,
                    $share,
                    $currency,
                    // Every third posting accrues to escrow instead of a user.
                    userId: $i % 3 === 0 ? null : $users[$i % 3]->id,
                ) : null,
            ])));

            // Reverse every seventh — corrections are part of normal operation.
            if ($i % 7 === 0) {
                ledger()->reverse($tx, "prop:{$i}:void");
            }
        }

        foreach (['EUR', 'USD'] as $currency) {
            $debits = (int) LedgerEntry::query()->where('currency', $currency)->where('direction', LedgerDirection::Debit)->sum('amount');
            $credits = (int) LedgerEntry::query()->where('currency', $currency)->where('direction', LedgerDirection::Credit)->sum('amount');

            expect($debits)->toBe($credits)->and($debits)->toBeGreaterThan(0);
        }

        expect(ledger()->verifyInvariants()->isHealthy())->toBeTrue();
    });
});
