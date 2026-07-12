<?php

namespace App\Models;

use App\Services\Places\PlaceMerger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Audit row for an admin place merge (T-035): who folded which place into
 * which survivor, plus the snapshots {@see PlaceMerger}
 * needs to reverse the merge. `undone_at` marks a consumed (unmerged) row.
 *
 * @property int $id
 * @property int $source_place_id
 * @property int $target_place_id
 * @property int|null $performed_by_user_id
 * @property list<int> $rehomed_place_source_ids
 * @property list<array<string, mixed>> $dropped_duplicate_place_sources
 * @property array<string, mixed> $source_snapshot
 * @property list<array<string, mixed>> $target_tag_pivots
 * @property array<string, mixed> $target_backfilled_fields
 * @property Carbon|null $undone_at
 */
class PlaceMerge extends Model
{
    protected $fillable = [
        'source_place_id', 'target_place_id', 'performed_by_user_id',
        'rehomed_place_source_ids', 'dropped_duplicate_place_sources',
        'source_snapshot', 'target_tag_pivots', 'target_backfilled_fields',
        'undone_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rehomed_place_source_ids' => 'array',
            'dropped_duplicate_place_sources' => 'array',
            'source_snapshot' => 'array',
            'target_tag_pivots' => 'array',
            'target_backfilled_fields' => 'array',
            'undone_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Place, $this> */
    public function sourcePlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'source_place_id');
    }

    /** @return BelongsTo<Place, $this> */
    public function targetPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'target_place_id');
    }

    /** @return BelongsTo<User, $this> */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
