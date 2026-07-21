<?php

namespace App\Jobs;

use App\Enums\ShareStatus;
use App\Jobs\Concerns\FailsShareOnError;
use App\Jobs\Concerns\RecordsStageMetrics;
use App\Models\Share;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Shared shape for the pipeline stages whose real work lands in later tasks
 * (T-017/T-018/T-021/T-023/T-024). Each guards on the expected share status
 * (so a share parked in `review`/`failed` no-ops the rest of the chain),
 * records a stage metric, then runs its (currently no-op) body.
 */
abstract class PipelineStubJob implements ShouldQueue
{
    use Batchable, Dispatchable, FailsShareOnError, InteractsWithQueue, Queueable, RecordsStageMetrics, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $shareId)
    {
        $this->onQueue($this->queueName());
    }

    abstract protected function stage(): string;

    // NOT named queue() — Laravel's Dispatcher treats a job's queue() method as a
    // custom queue-dispatch handler.
    abstract protected function queueName(): string;

    abstract protected function expectedStatus(): ShareStatus;

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ["share:{$this->shareId}", "stage:{$this->stage()}"];
    }

    /**
     * Close this job's stage metric on success/failure (T-093). Only fires when
     * run through the queue worker; a direct ->handle() in a test bypasses it.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return $this->stageMetricMiddleware();
    }

    public function handle(): void
    {
        $share = Share::find($this->shareId);

        if ($share === null || $share->status !== $this->expectedStatus()) {
            return; // not our turn (or share parked/terminal) — exit silently
        }

        $this->recordStage($share->id, $this->stage());
        $this->run($share);
    }

    /** No-op until the real stage lands. */
    protected function run(Share $share): void {}
}
