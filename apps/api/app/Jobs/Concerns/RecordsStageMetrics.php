<?php

namespace App\Jobs\Concerns;

use App\Models\ShareStageMetric;

/**
 * Writes one share_stage_metrics row per stage execution. GET /shares/{id}'s
 * status_history is derived from these.
 */
trait RecordsStageMetrics
{
    protected function recordStage(int $shareId, string $stage, string $status, ?int $durationMs = null, int $attempt = 1): void
    {
        ShareStageMetric::create([
            'share_id' => $shareId,
            'stage' => $stage,
            'status' => $status,
            'started_at' => now(),
            'duration_ms' => $durationMs,
            'attempt' => $attempt,
        ]);
    }
}
