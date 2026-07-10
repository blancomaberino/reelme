<?php

namespace App\Jobs\Concerns;

use App\Models\ShareStageMetric;

/**
 * Writes one share_stage_metrics row per stage execution. GET /shares/{id}'s
 * status_history is derived from these.
 */
trait RecordsStageMetrics
{
    protected function recordStage(int $shareId, string $stage): void
    {
        // duration/attempt columns exist for when real stages measure them (T-017+).
        ShareStageMetric::create([
            'share_id' => $shareId,
            'stage' => $stage,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }
}
