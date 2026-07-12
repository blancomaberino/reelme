<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's report against a review (T-059) — one per (review, user), feeding
 * the Filament moderation queue. Reasons are constrained by a DB CHECK.
 *
 * @property int $id
 * @property int $review_id
 * @property int $user_id
 * @property string $reason
 */
class ReviewReport extends Model
{
    public const REASONS = ['spam', 'offensive', 'off_topic', 'other'];

    public $timestamps = false; // created_at only, set by the DB default

    protected $fillable = ['review_id', 'user_id', 'reason'];

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
