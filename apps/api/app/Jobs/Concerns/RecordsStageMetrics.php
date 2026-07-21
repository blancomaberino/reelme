<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\TracksStageMetric;
use App\Models\ShareStageMetric;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-stage telemetry for a pipeline job (T-093). {@see recordStage()} opens a
 * `share_stage_metrics` row (`running`) when a stage begins real work; the
 * {@see TracksStageMetric} job middleware then closes it — `completed` +
 * `duration_ms` on success, `failed` on a throw — via {@see completeCurrentStage()}
 * / {@see failCurrentStage()}. Each is also an entry/exit log line carrying
 * `share_id` + `request_id` (T-092) so a stage's timing and failures are
 * queryable, not just a bare "running" marker.
 *
 * A job that returns before calling recordStage() (an idempotent no-op re-entry)
 * records nothing — the middleware only closes a stage that actually opened.
 */
trait RecordsStageMetrics
{
    private ?int $currentStageMetricId = null;

    private ?string $currentStageName = null;

    private ?float $currentStageStartedAt = null;

    protected function recordStage(int $shareId, string $stage): void
    {
        $metric = ShareStageMetric::create([
            'share_id' => $shareId,
            'stage' => $stage,
            'status' => 'running',
            'started_at' => now(),
            'attempt' => $this->currentAttempt(),
        ]);

        $this->currentStageMetricId = $metric->id;
        $this->currentStageName = $stage;
        $this->currentStageStartedAt = microtime(true);

        Log::info("pipeline.{$stage}.start", $this->stageLogContext());
    }

    /** Success path — the middleware calls this after handle() returns. */
    public function completeCurrentStage(): void
    {
        if ($this->currentStageMetricId === null) {
            return; // no stage opened (idempotent no-op) — nothing to close
        }

        $duration = $this->currentStageDurationMs();
        $stage = $this->currentStageName;

        ShareStageMetric::query()->whereKey($this->currentStageMetricId)
            ->update(['status' => 'completed', 'duration_ms' => $duration]);

        Log::info("pipeline.{$stage}.done", $this->stageLogContext(['duration_ms' => $duration]));

        $this->resetStage();
    }

    /** Failure path — the middleware calls this when handle() throws. */
    public function failCurrentStage(Throwable $e): void
    {
        if ($this->currentStageMetricId === null) {
            return; // threw before the stage opened — no row to fail
        }

        $duration = $this->currentStageDurationMs();
        $stage = $this->currentStageName;

        ShareStageMetric::query()->whereKey($this->currentStageMetricId)
            ->update(['status' => 'failed', 'duration_ms' => $duration]);

        Log::warning("pipeline.{$stage}.failed", $this->stageLogContext([
            'duration_ms' => $duration,
            'error' => $e->getMessage(),
        ]));

        $this->resetStage();
    }

    /**
     * The job middleware that closes the stage a job opens.
     *
     * @return array<int, object>
     */
    protected function stageMetricMiddleware(): array
    {
        return [new TracksStageMetric];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function stageLogContext(array $extra = []): array
    {
        return [
            'share_id' => $this->shareId,
            'stage' => $this->currentStageName,
            'request_id' => Context::get('request_id'),
            ...$extra,
        ];
    }

    private function currentStageDurationMs(): int
    {
        if ($this->currentStageStartedAt === null) {
            return 0;
        }

        return (int) round((microtime(true) - $this->currentStageStartedAt) * 1000);
    }

    private function currentAttempt(): int
    {
        // attempts() (from InteractsWithQueue, which every pipeline job uses) is
        // 1-based on a real queued job and 0 when run outside a worker (a direct
        // ->handle() in a unit test) — normalize both to ≥ 1.
        return max(1, (int) $this->attempts());
    }

    private function resetStage(): void
    {
        $this->currentStageMetricId = null;
        $this->currentStageName = null;
        $this->currentStageStartedAt = null;
    }
}
