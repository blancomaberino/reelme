<?php

namespace App\Models;

use App\Enums\ClaimMethod;
use App\Enums\ClaimStatus;
use Database\Factories\InfluencerClaimFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Audit + working state for an influencer identity claim (T-038). The verified
 * verdict is mirrored onto `influencers.claimed_by_user_id` (the source of truth);
 * this row keeps the bio-code token, expiry, and rejection/dispute history.
 *
 * @property int $id
 * @property int $influencer_id
 * @property int $user_id
 * @property ClaimMethod $method
 * @property ClaimStatus $status
 * @property string|null $token
 * @property string|null $reason
 * @property Carbon|null $expires_at
 * @property int|null $reviewed_by_user_id
 */
class InfluencerClaim extends Model
{
    /** @use HasFactory<InfluencerClaimFactory> */
    use HasFactory;

    protected $fillable = [
        'influencer_id', 'user_id', 'method', 'status', 'token', 'reason',
        'expires_at', 'reviewed_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => ClaimMethod::class,
            'status' => ClaimStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    /** A pending bio-code token past its 72h window can no longer verify. */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** @return BelongsTo<Influencer, $this> */
    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
