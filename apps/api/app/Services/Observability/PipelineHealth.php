<?php

namespace App\Services\Observability;

use App\Enums\ShareStatus;
use App\Jobs\Pipeline;
use App\Models\Share;
use App\Models\ShareStageMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Read-only aggregates behind the Filament pipeline-health dashboard (T-107).
 *
 * Every figure comes from data the pipeline already writes — `shares.status`,
 * `shares.failure_reason`, and the `share_stage_metrics` rows T-093 added — so
 * this collects nothing new. Before it, an operator diagnosed "is the pipeline
 * healthy right now?" by eyeballing the Shares table.
 *
 * The aggregation lives here rather than in the widgets so it can be tested
 * against seeded fixtures directly, without rendering Livewire.
 *
 * Every query is a grouped aggregate over an indexed time window — no row
 * hydration, no N+1 — so the dashboard stays cheap on a large table.
 */
class PipelineHealth
{
    /** Percentile boundaries reported per stage. */
    private const PERCENTILES = [0.5, 0.95];

    /**
     * Share counts by status in the window, every status present (zero-filled)
     * so a status vanishing from the result set reads as "none" rather than
     * silently disappearing from the dashboard.
     *
     * @return array<string, int>
     */
    public function shareStatusCounts(Carbon $since): array
    {
        /** @var array<string, int> $counts */
        $counts = Share::query()
            ->where('created_at', '>=', $since)
            ->groupBy('status')
            ->selectRaw('status, count(*) AS total')
            ->pluck('total', 'status')
            ->all();

        $zeroed = [];
        foreach (ShareStatus::cases() as $status) {
            $zeroed[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $zeroed;
    }

    /**
     * The failure-code mix, commonest first — which failure an operator should
     * be chasing, not merely how many there were.
     *
     * `failure_reason` is also set on `review` shares (a recoverable stall the
     * user can resolve by hand), and those are exactly as diagnostic as a hard
     * failure, so both are counted.
     *
     * @return list<array{reason: string, total: int}>
     */
    public function failureMix(Carbon $since, int $limit = 10): array
    {
        // `toBase()`: the rows are aggregates, not Shares — hydrating models
        // for a two-column count would be waste, and the columns do not exist
        // on the model.
        return Share::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('failure_reason')
            ->whereIn('status', [ShareStatus::Failed->value, ShareStatus::Review->value])
            ->groupBy('failure_reason')
            ->selectRaw('failure_reason AS reason, count(*) AS total')
            ->orderByDesc('total')
            ->orderBy('reason') // stable order for equal counts
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(fn (object $row) => ['reason' => (string) $row->reason, 'total' => (int) $row->total])
            ->all();
    }

    /**
     * Per-stage throughput and latency, in pipeline order.
     *
     * Percentiles are computed by Postgres (`percentile_cont`) rather than in
     * PHP: pulling every duration back to rank it would be the one query here
     * that does not scale.
     *
     * Only closed stages count toward the timings — a `running` row has no
     * duration yet, and treating its NULL as 0 would quietly drag every p50
     * down whenever the pipeline is busy.
     *
     * @return list<array{stage: string, runs: int, failed: int, failureRate: float, p50: int|null, p95: int|null}>
     */
    public function stageDurations(Carbon $since): array
    {
        $rows = ShareStageMetric::query()
            ->where('started_at', '>=', $since)
            ->groupBy('stage')
            ->selectRaw('stage')
            ->selectRaw('count(*) AS runs')
            ->selectRaw("count(*) FILTER (WHERE status = 'failed') AS failed")
            ->selectRaw("percentile_cont(?) WITHIN GROUP (ORDER BY duration_ms) FILTER (WHERE status <> 'running') AS p50", [self::PERCENTILES[0]])
            ->selectRaw("percentile_cont(?) WITHIN GROUP (ORDER BY duration_ms) FILTER (WHERE status <> 'running') AS p95", [self::PERCENTILES[1]])
            ->get()
            ->keyBy('stage');

        $out = [];
        // Pipeline order, not count order: an operator reads this top-to-bottom
        // as the path a share takes, so a stage with no runs is a gap worth
        // seeing rather than a row to omit.
        foreach (array_keys(Pipeline::STAGES) as $stage) {
            $row = $rows->get($stage);
            $runs = (int) ($row->runs ?? 0);
            $failed = (int) ($row->failed ?? 0);

            $out[] = [
                'stage' => $stage,
                'runs' => $runs,
                'failed' => $failed,
                'failureRate' => $runs > 0 ? round($failed / $runs * 100, 1) : 0.0,
                'p50' => isset($row->p50) ? (int) round((float) $row->p50) : null,
                'p95' => isset($row->p95) ? (int) round((float) $row->p95) : null,
            ];
        }

        return $out;
    }

    /**
     * Pending job count per Horizon queue, plus the total.
     *
     * `null` for a queue whose driver cannot answer — the dashboard degrades to
     * "unknown" rather than 500ing when Redis is unreachable, which is exactly
     * the moment an operator is most likely to be looking at it.
     *
     * @return array{queues: array<string, int|null>, total: int|null}
     */
    public function queueDepth(): array
    {
        $queues = [];
        $total = 0;
        $anyKnown = false;

        foreach ($this->configuredQueues() as $queue) {
            try {
                $size = Queue::size($queue);
                $queues[$queue] = $size;
                $total += $size;
                $anyKnown = true;
            } catch (Throwable) {
                $queues[$queue] = null;
            }
        }

        return ['queues' => $queues, 'total' => $anyKnown ? $total : null];
    }

    /**
     * How long the oldest still-running stage has been running, in seconds.
     *
     * The honest stand-in for "oldest job": a `running` row that never closed is
     * a stage that died mid-flight or is wedged, and that is the number worth
     * alarming on. Reading it from our own table rather than the queue backend
     * also means it survives a Redis flush.
     */
    public function oldestRunningStageSeconds(): ?int
    {
        $oldest = ShareStageMetric::query()
            ->where('status', 'running')
            ->min('started_at');

        return $oldest === null ? null : (int) max(0, now()->diffInSeconds(Carbon::parse($oldest), absolute: true));
    }

    /**
     * The distinct queue names Horizon is configured to work, in config order.
     *
     * @return list<string>
     */
    private function configuredQueues(): array
    {
        /** @var array<string, array<string, mixed>> $supervisors */
        $supervisors = config('horizon.defaults', []);

        $queues = [];
        foreach ($supervisors as $supervisor) {
            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                $queues[] = (string) $queue;
            }
        }

        return array_values(array_unique($queues !== [] ? $queues : ['default']));
    }

    /**
     * Total rows the durations table is summarising — lets a widget say
     * "no stage ran in this window" instead of showing seven empty rows.
     */
    public function stageRunCount(Carbon $since): int
    {
        return ShareStageMetric::query()->where('started_at', '>=', $since)->count();
    }
}
