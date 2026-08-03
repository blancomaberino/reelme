<?php

namespace App\Models;

use App\Enums\LedgerAccount;
use App\Enums\LedgerDirection;
use App\Services\Ledger\LedgerService;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of a double-entry transaction (T-044, 02 §3.15).
 *
 * **Append-only, twice over.** The database has a trigger that refuses UPDATE
 * and DELETE; this model refuses them too, so application code fails with a
 * readable `LogicException` at the call site instead of a Postgres error from
 * six frames down. A correction is a reversing entry — that is what keeps the
 * ledger able to answer "what did we believe last Tuesday", which is the only
 * question that matters when a restaurant disputes an invoice.
 *
 * **No balance is stored anywhere.** Ask {@see LedgerService::balance()};
 * it sums these rows. A cached balance is a second source of truth for money.
 *
 * @property int $id
 * @property string $transaction_uuid
 * @property LedgerAccount $account
 * @property LedgerDirection $direction
 * @property int $amount
 * @property string $currency
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $user_id
 * @property string $idempotency_key
 * @property string|null $memo
 * @property Carbon $created_at
 */
class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory;

    /** 02 §3.15: created_at only. There is no update, so there is nothing to stamp. */
    public const UPDATED_AT = null;

    /**
     * Nothing is mass-assignable. Every line is constructed by
     * {@see LedgerService}, which is the only thing that
     * knows how to keep a transaction balanced — a caller who could build a row
     * directly could build half a transaction.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected static function booted(): void
    {
        /*
         * The same rule the database trigger enforces, raised here so it fails
         * where the mistake was made. Both exist on purpose: this one is
         * readable, that one is unavoidable.
         */
        static::updating(function (): never {
            throw new LogicException('ledger_entries is append-only — correct with a reversing entry (02 §3.15).');
        });

        static::deleting(function (): never {
            throw new LogicException('ledger_entries is append-only — a deletion would erase the audit trail (02 §3.15).');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account' => LedgerAccount::class,
            'direction' => LedgerDirection::class,
            'amount' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Escrow: earnings owed to an influencer identity nobody has claimed yet
     * (06 §5.3). The money is owed; we do not yet know to whom.
     *
     * @param  Builder<LedgerEntry>  $query
     */
    protected function scopeEscrow(Builder $query): void
    {
        $query->where('account', LedgerAccount::InfluencerEarnings)->whereNull('user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
