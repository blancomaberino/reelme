<?php

namespace App\Services\Ledger;

use App\Enums\LedgerAccount;
use App\Enums\LedgerDirection;
use App\Exceptions\UnbalancedTransaction;
use App\Models\Influencer;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only way money is recorded (T-044, 02 §3.15, 06 §4.2).
 *
 * Three rules, and every method here exists to keep one of them true:
 *
 * 1. **Debits equal credits, per currency, per transaction.** Checked before a
 *    single row is written, so an unbalanced set never lands even partially.
 * 2. **Nothing is ever updated or deleted.** Corrections are reversing entries
 *    ({@see reverse()}). The model and a database trigger both refuse the
 *    alternative.
 * 3. **Balances are derived, never stored.** {@see balance()} sums rows. A
 *    cached balance is a second source of truth for money, and the two diverge.
 *
 * Idempotency is the fourth thing, and it is what makes the ledger safe to call
 * from a retried path: each line's key is `{prefix}:{n}`, unique in the
 * database, so posting "the fee for redemption 123" twice returns the first
 * transaction instead of writing a second fee.
 */
class LedgerService
{
    /**
     * Write one balanced transaction.
     *
     * Joins an outer transaction when there is one — which is the entire point
     * on the redemption path: T-043 dispatches `RedemptionVerified` inside its
     * verify transaction, so these entries commit with the status flip or not at
     * all. A fee posted for a redemption that rolled back is money invented; a
     * redemption that committed without its fee is a free meal.
     *
     * @param  list<LedgerLine>  $lines
     *
     * @throws UnbalancedTransaction
     */
    public function record(
        string $idempotencyKeyPrefix,
        array $lines,
        ?Model $reference = null,
        ?string $memo = null,
    ): LedgerTransaction {
        $this->assertBalanced($lines);

        return DB::transaction(function () use ($idempotencyKeyPrefix, $lines, $reference, $memo): LedgerTransaction {
            // Checked first so a replay is a cheap read rather than a write that
            // fails. The insert below is still guarded — this is the fast path,
            // the unique index is the guarantee.
            $existing = $this->findByPrefix($idempotencyKeyPrefix);

            if ($existing !== null) {
                return $existing;
            }

            $uuid = (string) Str::uuid();

            try {
                $entries = $this->insertLines($uuid, $idempotencyKeyPrefix, $lines, $reference, $memo);
            } catch (UniqueConstraintViolationException) {
                // Someone else posted the same prefix between our read and our
                // insert. Their transaction is as good as ours would have been.
                $replay = $this->findByPrefix($idempotencyKeyPrefix);

                if ($replay === null) {
                    throw new UnbalancedTransaction(
                        "Idempotency key '{$idempotencyKeyPrefix}' collided but no matching transaction was found — ".
                        'the key namespace is being shared by two different postings.'
                    );
                }

                return $replay;
            }

            return new LedgerTransaction($uuid, $entries);
        });
    }

    /**
     * The balance of an account, optionally for one party, in one currency.
     *
     * Signed against the account's NORMAL direction, so a positive number always
     * means "more of what this account is for" — a receivable that is owed,
     * earnings that are payable. Callers never have to remember which way round
     * a given account runs.
     *
     * Passing `$user = null` means "the rows with no user", NOT "all users" —
     * on `influencer_earnings` that is precisely the escrow balance, and
     * conflating the two would report unclaimed money as somebody's.
     */
    public function balance(LedgerAccount $account, ?User $user = null, ?string $currency = null): int
    {
        $currency ??= $this->currency();

        $sums = LedgerEntry::query()
            ->where('account', $account)
            ->where('currency', $currency)
            ->when($user !== null, fn ($q) => $q->where('user_id', $user->id))
            ->when($user === null, fn ($q) => $q->whereNull('user_id'))
            ->toBase()
            ->selectRaw('coalesce(sum(case when direction = ? then amount else 0 end), 0) AS normal_side', [$account->normalDirection()->value])
            ->selectRaw('coalesce(sum(case when direction = ? then amount else 0 end), 0) AS other_side', [$account->normalDirection()->opposite()->value])
            ->first();

        return (int) ($sums->normal_side ?? 0) - (int) ($sums->other_side ?? 0);
    }

    /**
     * Every party's balance for an account, keyed by user id.
     *
     * The payout run's work list (T-045): one query rather than one per
     * influencer. `null` is not a key here — escrow is asked for by name via
     * {@see escrowBalance()}, so a payout run cannot accidentally sweep money
     * that belongs to nobody yet.
     *
     * @return array<int, int>
     */
    public function balancesByUser(LedgerAccount $account, ?string $currency = null): array
    {
        $currency ??= $this->currency();
        $normal = $account->normalDirection()->value;

        return LedgerEntry::query()
            ->where('account', $account)
            ->where('currency', $currency)
            ->whereNotNull('user_id')
            ->toBase()
            ->groupBy('user_id')
            ->selectRaw('user_id')
            ->selectRaw(
                'coalesce(sum(case when direction = ? then amount else -amount end), 0) AS balance',
                [$normal],
            )
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->user_id => (int) $row->balance])
            ->all();
    }

    /**
     * Money owed to an influencer identity nobody has claimed (06 §5.3).
     *
     * Derived through the REFERENCE, not through a user column — that is the
     * whole point of escrow: there is no user yet. The join walks
     * `ledger_entries → redemptions → attributed_influencer_id`, which is
     * exactly the chain that survives the underlying share being deleted
     * (T-043 froze the influencer id onto the redemption).
     */
    public function escrowBalance(Influencer $influencer, ?string $currency = null): int
    {
        $currency ??= $this->currency();
        $normal = LedgerAccount::InfluencerEarnings->normalDirection()->value;

        $sum = LedgerEntry::query()
            ->escrow()
            ->where('currency', $currency)
            ->where(function ($q) use ($influencer): void {
                // What accrued: escrow credits on redemptions attributed to
                // this identity.
                $q->where(fn ($sub) => $sub
                    ->where('reference_type', 'redemption')
                    ->whereIn('reference_id', DB::table('redemptions')
                        ->select('id')
                        ->where('attributed_influencer_id', $influencer->id)))
                    // ...minus what was released. The release debits escrow and
                    // references the INFLUENCER, not a redemption — it settles
                    // many at once. Without this side the balance would still
                    // read the full amount after the money had already moved,
                    // and a second claim could release it twice.
                    ->orWhere(fn ($sub) => $sub
                        ->where('reference_type', 'influencer')
                        ->where('reference_id', $influencer->id));
            })
            ->toBase()
            ->selectRaw(
                'coalesce(sum(case when direction = ? then amount else -amount end), 0) AS balance',
                [$normal],
            )
            ->value('balance');

        return (int) $sum;
    }

    /**
     * Undo a transaction by posting its mirror.
     *
     * The ONLY correction mechanism (02 §3.15). Both the original and the
     * reversal stay in the table, which is what lets an audit reconstruct not
     * just the final position but the mistake and when it was noticed.
     *
     * @throws UnbalancedTransaction
     */
    public function reverse(LedgerTransaction $transaction, string $idempotencyKeyPrefix, ?string $memo = null): LedgerTransaction
    {
        $lines = $transaction->entries
            ->map(fn (LedgerEntry $entry) => new LedgerLine(
                $entry->account,
                $entry->direction->opposite(),
                $entry->amount,
                $entry->currency,
                $entry->user_id,
                $memo,
            ))
            ->values()
            ->all();

        return $this->record(
            $idempotencyKeyPrefix,
            $lines,
            reference: null,
            memo: $memo ?? 'Reversal of '.$transaction->uuid,
        );
    }

    /**
     * The nightly check (02 §3.15): does every transaction still balance?
     *
     * Belt and braces — `record()` already refuses to write an imbalance, so a
     * violation here means something bypassed the service or the data was
     * tampered with. That is exactly why it is worth running: the invariant
     * that "cannot" break is the one nobody would notice breaking.
     */
    public function verifyInvariants(): InvariantReport
    {
        $offenders = DB::table('ledger_entries')
            ->groupBy('transaction_uuid', 'currency')
            ->havingRaw(
                'sum(case when direction = ? then amount else 0 end) <> sum(case when direction = ? then amount else 0 end)',
                [LedgerDirection::Debit->value, LedgerDirection::Credit->value],
            )
            ->selectRaw('transaction_uuid, currency')
            ->selectRaw('sum(case when direction = ? then amount else 0 end) AS debits', [LedgerDirection::Debit->value])
            ->selectRaw('sum(case when direction = ? then amount else 0 end) AS credits', [LedgerDirection::Credit->value])
            ->get()
            ->map(fn ($row) => [
                'transaction_uuid' => (string) $row->transaction_uuid,
                'currency' => (string) $row->currency,
                'debits' => (int) $row->debits,
                'credits' => (int) $row->credits,
            ])
            ->all();

        $singletons = DB::table('ledger_entries')
            ->groupBy('transaction_uuid')
            ->havingRaw('count(*) < 2')
            ->pluck('transaction_uuid')
            ->map(fn ($uuid) => (string) $uuid)
            ->all();

        return new InvariantReport(
            checked: (int) DB::table('ledger_entries')->distinct()->count('transaction_uuid'),
            unbalanced: $offenders,
            singleEntryTransactions: $singletons,
        );
    }

    /** The transaction a prefix already produced, or null. */
    public function findByPrefix(string $idempotencyKeyPrefix): ?LedgerTransaction
    {
        // Escaped: `_` and `%` are LIKE wildcards, so an unescaped prefix
        // containing one would match keys belonging to a DIFFERENT posting —
        // and this method's answer is "has this already been paid". A false
        // match here suppresses a real fee; a false miss double-charges.
        $pattern = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $idempotencyKeyPrefix).':%';

        $entries = LedgerEntry::query()
            ->where('idempotency_key', 'like', $pattern)
            ->orderBy('id')
            ->get();

        if ($entries->isEmpty()) {
            return null;
        }

        return new LedgerTransaction(
            (string) $entries->first()->transaction_uuid,
            $entries,
            replayed: true,
        );
    }

    /**
     * @param  list<LedgerLine>  $lines
     *
     * @throws UnbalancedTransaction
     */
    private function assertBalanced(array $lines): void
    {
        if (count($lines) < 2) {
            throw UnbalancedTransaction::empty();
        }

        /** @var array<string, array{debits: int, credits: int}> $byCurrency */
        $byCurrency = [];

        foreach ($lines as $line) {
            if ($line->amount <= 0) {
                throw UnbalancedTransaction::nonPositiveAmount($line->amount);
            }

            $byCurrency[$line->currency] ??= ['debits' => 0, 'credits' => 0];
            $side = $line->direction === LedgerDirection::Debit ? 'debits' : 'credits';
            $byCurrency[$line->currency][$side] += $line->amount;
        }

        $unbalanced = array_filter($byCurrency, fn (array $sums) => $sums['debits'] !== $sums['credits']);

        if ($unbalanced !== []) {
            throw UnbalancedTransaction::forCurrencies($unbalanced);
        }
    }

    /**
     * @param  list<LedgerLine>  $lines
     * @return Collection<int, LedgerEntry>
     */
    private function insertLines(
        string $uuid,
        string $prefix,
        array $lines,
        ?Model $reference,
        ?string $memo,
    ): Collection {
        $referenceType = $reference === null ? null : $this->referenceType($reference);
        $referenceId = $reference?->getKey();

        $entries = new Collection;

        foreach ($lines as $index => $line) {
            $entry = new LedgerEntry;
            $entry->forceFill([
                'transaction_uuid' => $uuid,
                'account' => $line->account,
                'direction' => $line->direction,
                'amount' => $line->amount,
                'currency' => $line->currency,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'user_id' => $line->userId,
                // `{prefix}:{n}` — the index makes each LINE unique while the
                // prefix identifies the transaction, so a partial replay is
                // impossible: either every line lands or the first collides.
                'idempotency_key' => $prefix.':'.$index,
                'memo' => $line->memo ?? $memo,
                'created_at' => now(),
            ])->save();

            $entries->push($entry);
        }

        return $entries;
    }

    /**
     * The morph name: `redemption`, `payout`. The short form 02 §3.15 specifies,
     * not the FQCN — the column is varchar(32) and a class name is a refactor
     * away from breaking every historical row.
     */
    private function referenceType(Model $reference): string
    {
        return Str::snake(class_basename($reference));
    }

    private function currency(): string
    {
        return (string) config('monetization.currency');
    }
}
