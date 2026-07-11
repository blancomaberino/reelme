<?php

namespace App\Models;

use App\Enums\ShareStatus;
use App\Events\ShareStatusChanged;
use App\Exceptions\InvalidShareTransition;
use Database\Factories\ShareFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $source_post_id
 * @property int|null $analysis_run_id
 * @property ShareStatus $status
 * @property string|null $failure_reason
 * @property string|null $review_reason
 * @property array<string, mixed>|null $review_meta_json
 * @property array<string, mixed>|null $corrected_extraction_json
 * @property bool $user_confirmed
 * @property int|null $published_place_source_id
 * @property string|null $shared_via
 * @property Carbon|null $published_at
 */
class Share extends Model
{
    /** @use HasFactory<ShareFactory> */
    use HasFactory;

    // Only what a sharer legitimately supplies. `user_id` comes from auth,
    // `status`/`failure_reason`/`published_at` are driven by the pipeline state
    // machine — never mass-assigned. (Factories bypass this via Model::unguarded.)
    protected $fillable = ['source_post_id', 'shared_via'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShareStatus::class,
            'review_meta_json' => 'array',
            'corrected_extraction_json' => 'array',
            'user_confirmed' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<SourcePost, $this> */
    public function sourcePost(): BelongsTo
    {
        return $this->belongsTo(SourcePost::class);
    }

    /** @return HasMany<AnalysisRun, $this> */
    public function analysisRuns(): HasMany
    {
        return $this->hasMany(AnalysisRun::class);
    }

    /**
     * The winning run selected by ExtractPlaceData (T-021).
     *
     * @return BelongsTo<AnalysisRun, $this>
     */
    public function analysisRun(): BelongsTo
    {
        return $this->belongsTo(AnalysisRun::class);
    }

    /**
     * The place_source this share published (set by PublishShare).
     *
     * @return BelongsTo<PlaceSource, $this>
     */
    public function publishedPlaceSource(): BelongsTo
    {
        return $this->belongsTo(PlaceSource::class, 'published_place_source_id');
    }

    /** @return HasMany<ShareStageMetric, $this> */
    public function stageMetrics(): HasMany
    {
        return $this->hasMany(ShareStageMetric::class);
    }

    /** @return HasMany<ShareCorrection, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(ShareCorrection::class);
    }

    public function canTransitionTo(ShareStatus $to): bool
    {
        return in_array($to, $this->status->transitions(), true);
    }

    /**
     * Move the share to a new status. Throws on an illegal transition
     * (programming error). Uses an optimistic `WHERE status = :expected` guard;
     * if another worker already moved the row, returns false so the caller (a
     * pipeline job) can exit silently rather than double-process.
     */
    public function transitionTo(ShareStatus $to, ?string $failureReason = null): bool
    {
        if (! $this->canTransitionTo($to)) {
            throw new InvalidShareTransition($this->status, $to);
        }

        $from = $this->status;

        // Always write failure_reason: a reason-less transition (e.g. retry
        // Review/Failed → Fetching) must CLEAR any stale reason, otherwise a
        // share that later re-enters Review for a legit reason still surfaces the
        // old error. Write updated_at too so the in-memory model matches the row
        // the builder updates (status_history timestamps are serialized from it).
        $updates = [
            'status' => $to->value,
            'failure_reason' => $failureReason,
            'updated_at' => now(),
        ];
        if ($to === ShareStatus::Published) {
            $updates['published_at'] = now();
        }

        $affected = static::query()
            ->whereKey($this->getKey())
            ->where('status', $from->value)
            ->update($updates);

        if ($affected === 0) {
            return false;
        }

        $this->forceFill($updates)->syncOriginal();
        event(new ShareStatusChanged($this, $from, $to));

        return true;
    }
}
