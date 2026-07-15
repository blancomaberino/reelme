<?php

namespace App\Models;

use Database\Factories\PlaceSourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A post/share's contribution to a canonical place (02 §3.9) — the provenance
 * and attribution anchor. `extraction_snapshot_json` is the immutable extracted
 * place payload as of publish. A share may have several sources (one per place
 * in a multi-place post); `published_at` marks the ones that are live in the feed.
 *
 * @property int $id
 * @property int $place_id
 * @property int $source_post_id
 * @property int $share_id
 * @property int|null $analysis_run_id
 * @property array<string, mixed> $extraction_snapshot_json
 * @property bool $is_primary
 * @property Carbon|null $published_at
 */
class PlaceSource extends Model
{
    /** @use HasFactory<PlaceSourceFactory> */
    use HasFactory;

    protected $fillable = [
        'place_id', 'source_post_id', 'share_id', 'analysis_run_id',
        'extraction_snapshot_json', 'is_primary', 'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extraction_snapshot_json' => 'array',
            'is_primary' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Live-in-the-feed sources (a multi-place share publishes each resolved place
     * independently; an unresolved one stays unpublished pending review).
     *
     * @param  Builder<PlaceSource>  $query
     * @return Builder<PlaceSource>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    /** @return BelongsTo<Place, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /** @return BelongsTo<SourcePost, $this> */
    public function sourcePost(): BelongsTo
    {
        return $this->belongsTo(SourcePost::class);
    }

    /** @return BelongsTo<Share, $this> */
    public function share(): BelongsTo
    {
        return $this->belongsTo(Share::class);
    }

    /** @return BelongsTo<AnalysisRun, $this> */
    public function analysisRun(): BelongsTo
    {
        return $this->belongsTo(AnalysisRun::class);
    }
}
