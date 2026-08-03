<?php

namespace App\Models;

use App\Enums\RedemptionStatus;
use App\Services\Redemptions\RedemptionVerifier;
use Database\Factories\RedemptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use code a diner holds against an offer (T-043, 02 §3.14).
 *
 * This row is the payable event: `redeemed` is what a restaurant is billed for
 * and what an influencer earns from (06 §1, §3). Two consequences run through
 * the whole class:
 *
 * - **Attribution is frozen at issue.** `attributed_influencer_id` and
 *   `attributed_share_id` are denormalised copies, never recomputed. The share
 *   that sent the diner here can be edited, re-analysed or deleted afterwards;
 *   who earns from this visit was settled the moment the code was handed out.
 * - **Nothing here flips state.** The one transition that matters —
 *   `issued → redeemed` — lives in {@see RedemptionVerifier},
 *   inside a transaction with a guarded UPDATE, because a model setter cannot
 *   win a race against a second scan of the same QR.
 *
 * @property int $id
 * @property int $offer_id
 * @property int $user_id
 * @property string $code
 * @property string $qr_payload
 * @property RedemptionStatus $status
 * @property Carbon $issued_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $redeemed_at
 * @property int|null $redeemed_by_user_id
 * @property int|null $attributed_influencer_id
 * @property int|null $attributed_share_id
 * @property int|null $fee_amount
 * @property string|null $currency
 * @property bool|null $geofence_ok
 * @property int|null $geofence_distance_m
 */
class Redemption extends Model
{
    /** @use HasFactory<RedemptionFactory> */
    use HasFactory;

    /**
     * Deliberately narrow. Everything a REQUEST could influence is absent:
     * `status`, `code`, the attribution pair, the fee, and every timestamp are
     * written by the issuer and the verifier in code. A redemption whose status
     * or attribution could be mass-assigned is a fee that could be redirected.
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
            'status' => RedemptionStatus::class,
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'fee_amount' => 'integer',
            'geofence_ok' => 'boolean',
            'geofence_distance_m' => 'integer',
        ];
    }

    /**
     * Rows the expiry sweep should retire: still `issued`, window closed.
     *
     * @param  Builder<Redemption>  $query
     */
    protected function scopeOverdue(Builder $query): void
    {
        $query->where('status', RedemptionStatus::Issued)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /** Has the 24h window closed, whatever the column currently says? */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Can this code still be presented at a till right now? */
    public function isLive(): bool
    {
        return $this->status === RedemptionStatus::Issued && ! $this->hasExpired();
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * The diner holding the code.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The staff account that honoured it.
     *
     * @return BelongsTo<User, $this>
     */
    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_user_id');
    }

    /** @return BelongsTo<Influencer, $this> */
    public function attributedInfluencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class, 'attributed_influencer_id');
    }

    /** @return BelongsTo<Share, $this> */
    public function attributedShare(): BelongsTo
    {
        return $this->belongsTo(Share::class, 'attributed_share_id');
    }
}
