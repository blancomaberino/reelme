<?php

namespace App\Models;

use App\Services\Reviews\ReviewSource;
use App\Services\Reviews\Trustpilot\TrustpilotReviewRefresher;
use Database\Factories\ExternalPlaceReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A cached summary from an external review provider (T-082) — one row per
 * (place, source), populated out of band by that source's refresher (e.g.
 * {@see TrustpilotReviewRefresher}). The
 * matching {@see ReviewSource} driver reads this row only;
 * it never fetches on the request path.
 *
 * @property int $id
 * @property int $place_id
 * @property string $source
 * @property numeric-string|null $rating
 * @property int $review_count
 * @property string|null $url
 * @property array<int, array<string, mixed>>|null $snippets_json
 * @property Carbon $synced_at
 */
class ExternalPlaceReview extends Model
{
    /** @use HasFactory<ExternalPlaceReviewFactory> */
    use HasFactory;

    protected $fillable = [
        'place_id', 'source', 'rating', 'review_count', 'url', 'snippets_json', 'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'decimal:1',
            'review_count' => 'integer',
            'snippets_json' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Place, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }
}
