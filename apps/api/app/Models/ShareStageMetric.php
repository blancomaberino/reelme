<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $share_id
 * @property string $stage
 * @property string $status
 * @property Carbon|null $started_at
 * @property int|null $duration_ms
 * @property int $attempt
 */
class ShareStageMetric extends Model
{
    protected $fillable = ['share_id', 'stage', 'status', 'started_at', 'duration_ms', 'attempt'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'duration_ms' => 'integer',
            'attempt' => 'integer',
        ];
    }

    /** @return BelongsTo<Share, $this> */
    public function share(): BelongsTo
    {
        return $this->belongsTo(Share::class);
    }
}
