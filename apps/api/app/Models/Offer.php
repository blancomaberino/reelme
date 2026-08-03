<?php

namespace App\Models;

use App\Enums\OfferDiscountType;
use App\Enums\OfferStatus;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A restaurant promotion a diner can redeem (T-042, 02 §3.13).
 *
 * The offer is the billing unit of the restaurant program: a redemption against
 * it draws the flat fee (06 §2.3) and splits `influencer_share_bps` to the
 * attributed influencer (06 §4.1). Two things follow from that and shape this
 * class:
 *
 * - **Rows are never hard-deleted.** DELETE archives. Redemptions (T-043) and
 *   ledger entries (T-044) reference the offer, and a fee owed against a
 *   vanished row cannot be audited or disputed.
 * - **"Can this be redeemed?" has exactly one answer,** {@see isRedeemable()},
 *   never a `status === active` check at a call site. Redeemability is the
 *   conjunction of the status, the validity window, and two independent quotas,
 *   and T-043 issues against the same gate this class uses to render one.
 *
 * @property int $id
 * @property int $place_id
 * @property int $created_by_user_id
 * @property string $title
 * @property string|null $description
 * @property OfferDiscountType $discount_type
 * @property int $discount_value
 * @property string|null $terms
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $quota_total
 * @property int $quota_per_user
 * @property int|null $quota_per_day
 * @property int $redemptions_count
 * @property int $influencer_share_bps
 * @property OfferStatus $status
 */
class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory;

    /**
     * Mirrors of the column defaults, so a freshly created model answers the
     * same as one read back from the database. Without them the CREATE response
     * carries `quota_per_user: null` for a row Postgres stored as 1 — a payload
     * that contradicts the contract and the very next GET.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quota_per_user' => 1,
        'redemptions_count' => 0,
        'influencer_share_bps' => 1000,
        'status' => OfferStatus::Draft->value,
    ];

    /**
     * Exactly the fields an operator may set. The four the SYSTEM grants are
     * absent by design, following the same rule `User` states for its role
     * flags — they are assigned in code, never mass-assigned:
     *
     * - `place_id` / `created_by_user_id` — set from the AUTHORIZED place and
     *   the authenticated caller. Fillable, they would let a body re-point an
     *   offer at a venue the caller does not operate.
     * - `influencer_share_bps` — the platform's revenue split (06 §4.1). An
     *   operator setting their own share is the whole payout model inverted.
     * - `redemptions_count` — a counter cache the redemption pipeline (T-043)
     *   maintains. A body that could set it could reset a quota.
     *
     * The controller additionally passes an explicit `only()` allowlist, so
     * both the model and the write site have to agree before a column moves.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title', 'description', 'discount_type', 'discount_value', 'terms',
        'starts_at', 'ends_at', 'quota_total', 'quota_per_user', 'quota_per_day',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_type' => OfferDiscountType::class,
            'status' => OfferStatus::class,
            'discount_value' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'quota_total' => 'integer',
            'quota_per_user' => 'integer',
            'quota_per_day' => 'integer',
            'redemptions_count' => 'integer',
            'influencer_share_bps' => 'integer',
        ];
    }

    /**
     * Offers live for diners right now: marked active AND inside their window.
     *
     * The window half is not redundant with the status. `expired` is not
     * maintained by the database — an offer whose `ends_at` passed at 3am still
     * reads `active` until something writes to it — so a `status = 'active'`
     * filter alone would serve stale offers every morning. Evaluating the window
     * here means activeness is computed, and the column is only ever an
     * operator's INTENT.
     *
     * @param  Builder<Offer>  $query
     */
    protected function scopeActive(Builder $query): void
    {
        $query->where('status', OfferStatus::Active)
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $q) => $q
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>=', now()));
    }

    /**
     * Offers any diner may see: `active` ones, including those whose window has
     * yet to open so a scheduled promo can be advertised ahead of time. Drafts,
     * paused, and archived rows are the operator's business only.
     *
     * Broader than {@see scopeActive()} on purpose — that one answers "live
     * right now", this one answers "may be shown at all".
     *
     * @param  Builder<Offer>  $query
     */
    protected function scopePubliclyVisible(Builder $query): void
    {
        $query->where('status', OfferStatus::Active);
    }

    /**
     * The one gate on issuing a redemption (T-043) or advertising one as
     * redeemable today.
     *
     * `$issuedToday` is passed in rather than counted here: the `redemptions`
     * table arrives with T-043, and a per-day cap needs today's rows, not the
     * lifetime counter cache. The caller that has the table supplies the number;
     * the RULES for what exhausts an offer stay here, so T-043 cannot drift into
     * a second, subtly different definition. Default 0 = nothing issued today,
     * which is exactly true for every caller that predates the table.
     *
     * @param  int  $issuedToday  redemptions issued against this offer since midnight
     */
    public function isRedeemable(int $issuedToday = 0): bool
    {
        return $this->isWithinWindow()
            && $this->hasTotalQuotaLeft()
            && $this->hasDailyQuotaLeft($issuedToday);
    }

    /** Active intent AND inside the validity window — the scope, per row. */
    public function isWithinWindow(): bool
    {
        if ($this->status !== OfferStatus::Active) {
            return false;
        }

        $now = now();

        return ! $this->starts_at->isAfter($now)
            && ($this->ends_at === null || ! $this->ends_at->isBefore($now));
    }

    /**
     * Lifetime cap. Compares against `redemptions_count`, which T-043 keeps as
     * the count of NON-VOID redemptions: a voided or expired redemption returns
     * its slot to the quota, otherwise a run of abandoned codes would silently
     * retire an offer the restaurant is still paying to run.
     */
    public function hasTotalQuotaLeft(): bool
    {
        return $this->quota_total === null || $this->redemptions_count < $this->quota_total;
    }

    /** Per-day cap (06 §2.2) — an anti-fraud throttle, not a lifetime budget. */
    public function hasDailyQuotaLeft(int $issuedToday): bool
    {
        return $this->quota_per_day === null || $issuedToday < $this->quota_per_day;
    }

    /** Redemptions still available under the lifetime cap; null = unlimited. */
    public function remainingQuota(): ?int
    {
        return $this->quota_total === null
            ? null
            : max(0, $this->quota_total - $this->redemptions_count);
    }

    /** @return BelongsTo<Place, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
