<?php

namespace App\Models;

use App\Enums\ClaimStatus;
use App\Enums\PlaceClaimMethod;
use Database\Factories\PlaceClaimFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A restaurant operator's claim on a place (T-041, 06 §2.1).
 *
 * Unlike {@see InfluencerClaim}, whose verdict is mirrored onto the influencer
 * row, this table IS the source of truth for who operates a place: the verified
 * row is the answer, and the partial unique index guarantees there is at most
 * one of them. Nothing is denormalised onto `places`, so there is no second copy
 * to fall out of step.
 *
 * @property int $id
 * @property int $place_id
 * @property int $user_id
 * @property PlaceClaimMethod $method
 * @property ClaimStatus $status
 * @property array<string, mixed>|null $evidence_json
 * @property Carbon|null $verified_at
 * @property string|null $reason
 * @property int|null $reviewed_by_user_id
 */
class PlaceClaim extends Model
{
    /** @use HasFactory<PlaceClaimFactory> */
    use HasFactory;

    protected $fillable = [
        'place_id', 'user_id', 'method', 'status', 'evidence_json',
        'verified_at', 'reason', 'reviewed_by_user_id',
    ];

    /**
     * `evidence_json` holds a hashed OTP and a website token, so it is HIDDEN:
     * the claim is serialised back to its owner, and echoing the secret the
     * backend is about to compare against would defeat the check entirely.
     *
     * @var list<string>
     */
    protected $hidden = ['evidence_json'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PlaceClaimMethod::class,
            'status' => ClaimStatus::class,
            'evidence_json' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    /** Does this claim still need a human, or is it settled? */
    public function isPending(): bool
    {
        return $this->status === ClaimStatus::Pending;
    }

    /** @return BelongsTo<Place, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
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
