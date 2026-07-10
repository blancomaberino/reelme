<?php

namespace App\Models;

use App\Enums\ShareStatus;
use Database\Factories\ShareFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
