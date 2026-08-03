<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use App\Services\Payments\PayoutService;
use Database\Factories\PayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One transfer of earned money to an influencer (T-045, 02 §3.16).
 *
 * The row and the ledger hold are created together: requesting a payout debits
 * `influencer_earnings` and credits `payout_clearing` in the same transaction
 * that inserts this. That is what stops a second request from spending the same
 * euros while the first is in flight — the available balance drops immediately,
 * not when Stripe eventually answers.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $stripe_transfer_id
 * @property int $amount
 * @property string $currency
 * @property PayoutStatus $status
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string|null $failure_reason
 * @property string|null $idempotency_key
 * @property Carbon|null $paid_at
 */
class Payout extends Model
{
    /** @use HasFactory<PayoutFactory> */
    use HasFactory;

    /**
     * Nothing is mass-assignable: every field is set by
     * {@see PayoutService} or by a verified webhook, and
     * a payout whose amount or status could come from a request body is a
     * payout an attacker could write.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'amount' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
