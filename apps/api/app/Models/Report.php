<?php

namespace App\Models;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A user's flag against some piece of content (T-049, 02 §3.17).
 *
 * The polymorphic target is the whole design: places, shares, users, source
 * posts and offers are reportable for different reasons but need one queue, one
 * triage flow and one audit trail. A per-type table would have produced five of
 * each — and moderation coverage that is per-type is moderation coverage that
 * quietly has a gap.
 *
 * @property int $id
 * @property int $reporter_user_id
 * @property string $reportable_type
 * @property int $reportable_id
 * @property ReportReason $reason
 * @property string|null $details
 * @property ReportStatus $status
 * @property int|null $resolved_by_user_id
 * @property Carbon|null $resolved_at
 */
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    // reporter_user_id and status are set by the controller, never by request
    // input: a caller must not be able to file a report as somebody else, or
    // file one that arrives pre-resolved.
    protected $fillable = ['reportable_type', 'reportable_id', 'reason', 'details'];

    /**
     * Mirrors the column default so a freshly-created instance carries it in
     * memory too. A database default is invisible to the model that just
     * inserted the row — the API resource read `status->value` off null and
     * 500'd on the happy path, which no amount of DB-level correctness fixes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => ReportStatus::Open->value];

    /** @return MorphTo<Model, $this> */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /**
     * Still needs a human — the moderation queue's default view.
     *
     * @param  Builder<Report>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [ReportStatus::Open, ReportStatus::Reviewing]);
    }

    /**
     * Other live reports against the same target.
     *
     * The single most useful thing on a triage screen: one report is a
     * complaint, six against the same share is a pattern, and an admin deciding
     * without that number is deciding on a third of the evidence.
     *
     * @param  Builder<Report>  $query
     */
    public function scopeAgainstSameTarget(Builder $query): void
    {
        $query->where('reportable_type', $this->reportable_type)
            ->where('reportable_id', $this->reportable_id)
            ->whereKeyNot($this->getKey());
    }

    /** Close it out, recording who decided and when. */
    public function resolve(ReportStatus $status, User $admin): void
    {
        $this->forceFill([
            'status' => $status,
            'resolved_by_user_id' => $admin->id,
            'resolved_at' => now(),
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'resolved_at' => 'datetime',
        ];
    }
}
