<?php

namespace App\Models;

use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A native user review of a place — one per (place, user), 1–5 stars (02 §3.8).
 * Distinct from the cached Google review snippets on `places.google_reviews_json`.
 * Hidden reviews (moderation, T-059) drop out of every public surface and the
 * rating.app aggregate.
 *
 * @property int $id
 * @property int $place_id
 * @property int $user_id
 * @property int $rating
 * @property string|null $body
 * @property bool $is_hidden
 */
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    protected $fillable = [
        'place_id', 'user_id', 'rating', 'body',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_hidden' => 'boolean',
        ];
    }

    /**
     * Publicly visible reviews (not moderated away).
     *
     * @param  Builder<Review>  $query
     */
    protected function scopeVisible(Builder $query): void
    {
        $query->where('is_hidden', false);
    }

    /** @return HasMany<ReviewReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(ReviewReport::class);
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
}
