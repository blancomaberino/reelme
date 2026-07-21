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
use Illuminate\Support\Facades\Bus;

/**
 * Entry stage: transitions the share pending → fetching and dispatches the rest
 * of the pipeline as a Bus::chain. The source_post was already resolved +
 * created by POST /shares (the duplicate guard needs it synchronously), so this
 * job is the async kickoff. Idempotent: a re-delivery whose share already left
 * `pending` exits silently.
 */
class IngestShare implements ShouldQueue
{
    use Batchable, Dispatchable, FailsShareOnError, InteractsWithQueue, Queueable, RecordsStageMetrics, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120];

    public int $timeout = 30;

    public function __construct(public readonly int $shareId)
    {
        $this->onQueue('ingest');
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ["share:{$this->shareId}", 'stage:ingest'];
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

        if ($share === null || $share->status !== ShareStatus::Pending) {
            return; // already advanced (idempotent re-entry) — exit silently
        }

        $this->recordStage($share->id, 'ingest');

        if (! $share->transitionTo(ShareStatus::Fetching)) {
            return; // another worker moved it
        }

        Bus::chain(Pipeline::chain($share->id))->dispatch();
    }
}
