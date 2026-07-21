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
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

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

    public function handle(): void
    {
        $share = Share::find($this->shareId);

        if ($share === null || $share->status !== ShareStatus::Pending) {
            return; // already advanced (idempotent re-entry) — exit silently
        }

        // Entry log carrying the request that kicked off this async pipeline
        // (T-092): request_id rides the Context Laravel serialized onto this job,
        // so the whole share:N chain correlates back to the originating request.
        Log::info('pipeline.ingest.start', [
            'share_id' => $share->id,
            'request_id' => Context::get('request_id'),
        ]);

        $this->recordStage($share->id, 'ingest');

        if (! $share->transitionTo(ShareStatus::Fetching)) {
            return; // another worker moved it
        }

        Bus::chain(Pipeline::chain($share->id))->dispatch();
    }
}
