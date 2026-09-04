<?php

namespace App\Models;

use App\Observers\PlaceSourceObserver;
use Database\Factories\PlaceSourceFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
#[ObservedBy(PlaceSourceObserver::class)]
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

    /**
     * The dishes this source claims, in snapshot order (T-157).
     *
     * `id` ASC reproduces snapshot order because
     * {@see App\Services\Places\DishMaterializer} re-inserts the whole set in
     * one statement, walking the snapshot in order, and nothing else writes the
     * table.
     *
     * Eager-load it (`->with('dishes')`) anywhere the aggregation or a resource
     * reads dishes: `Model::preventLazyLoading()` is opted into per test file
     * here, not enabled suite-wide, so a missed load is a silent N+1 in
     * production rather than a failing test.
     *
     * @return HasMany<Dish, $this>
     */
    public function dishes(): HasMany
    {
        return $this->hasMany(Dish::class)->orderBy('id');
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
