<?php

namespace App\Services\Observability;

use App\Enums\AnalysisEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What the analysis pipeline is costing (T-051, NFR-13, 04 §8).
 *
 * Every query here aggregates `analysis_runs` — the source of truth — rather
 * than the Redis spend counter, which is a fast per-request pre-check a flush
 * can lose. Money questions get the durable answer.
 *
 * Results are cached for a minute. An admin dashboard that recomputes a 30-day
 * group-by on every poll is a dashboard that makes the database slower the more
 * closely it is watched.
 */
class AnalysisCosts
{
    private const TTL = 60;

    /**
     * Daily spend per engine, oldest first — the shape a chart wants.
     *
     * @return array{labels: list<string>, series: array<string, list<float>>}
     */
    public function dailyByEngine(int $days = 30): array
    {
        return $this->remember("daily:{$days}", function () use ($days): array {
            $since = Carbon::now('UTC')->startOfDay()->subDays($days - 1);

            /** @var Collection<int, object> $rows */
            $rows = DB::table('analysis_runs')
                // AT TIME ZONE 'UTC' explicitly: `date_trunc` on a timestamptz
                // truncates in the SESSION timezone, while the labels below are
                // built from UTC dates. On a managed Postgres with a non-UTC
                // default every bucket shifts, and any row whose shifted date
                // falls before the first label is dropped SILENTLY — spend
                // simply disappears from the chart with no error.
                ->selectRaw("date_trunc('day', finished_at AT TIME ZONE 'UTC') AS day, engine, SUM(cost_usd) AS cost")
                ->whereNotNull('finished_at')
                ->where('finished_at', '>=', $since)
                ->groupBy('day', 'engine')
                ->orderBy('day')
                ->get();

            // Every day in the window gets a point, including the ones with no
            // runs. Skipping empty days makes a gap look like a plateau and
            // hides exactly the outage an operator is looking for.
            $labels = [];
            for ($day = $since->copy(); $day <= Carbon::now('UTC')->startOfDay(); $day->addDay()) {
                $labels[] = $day->format('Y-m-d');
            }

            $series = [];
            foreach (AnalysisEngine::cases() as $engine) {
                $series[$engine->value] = array_fill(0, count($labels), 0.0);
            }

            foreach ($rows as $row) {
                $index = array_search(Carbon::parse($row->day)->format('Y-m-d'), $labels, true);

                if ($index !== false && isset($series[$row->engine])) {
                    $series[$row->engine][$index] = round((float) $row->cost, 4);
                }
            }

            return ['labels' => $labels, 'series' => $series];
        });
    }

    /**
     * Cost, volume and quality per model over a window.
     *
     * @return list<array{model: string, engine: string, runs: int, cost_usd: float, avg_confidence: float|null}>
     */
    public function byModel(Carbon $since): array
    {
        return $this->remember('model:'.$this->bucket($since), fn (): array => DB::table('analysis_runs')
            ->selectRaw('model, engine, COUNT(*) AS runs, SUM(cost_usd) AS cost, AVG(overall_confidence) AS confidence')
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $since)
            ->groupBy('model', 'engine')
            ->orderByDesc('cost')
            ->get()
            ->map(fn ($row) => [
                'model' => (string) $row->model,
                'engine' => (string) $row->engine,
                'runs' => (int) $row->runs,
                'cost_usd' => round((float) $row->cost, 4),
                'avg_confidence' => $row->confidence === null ? null : round((float) $row->confidence, 3),
            ])
            ->all());
    }

    /**
     * Who is spending the most, so a runaway account is visible before the bill.
     *
     * @return list<array{user_id: int, username: string|null, cost_usd: float, runs: int}>
     */
    public function topSpenders(Carbon $since, int $limit = 10): array
    {
        return $this->remember("spenders:{$this->bucket($since)}:{$limit}", fn (): array => DB::table('analysis_runs')
            ->join('shares', 'analysis_runs.share_id', '=', 'shares.id')
            ->leftJoin('users', 'shares.user_id', '=', 'users.id')
            ->selectRaw('shares.user_id, users.username, SUM(analysis_runs.cost_usd) AS cost, COUNT(*) AS runs')
            ->whereNotNull('analysis_runs.finished_at')
            ->where('analysis_runs.finished_at', '>=', $since)
            ->groupBy('shares.user_id', 'users.username')
            ->orderByDesc('cost')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'username' => $row->username,
                'cost_usd' => round((float) $row->cost, 4),
                'runs' => (int) $row->runs,
            ])
            ->all());
    }

    /**
     * The headline numbers.
     *
     * `fallback_rate` is the share of runs that went to the paid remote engine.
     * 04 §8 treats a sustained rate above 30% as a warning: it means local
     * inference is failing or refusing, and the cost curve has quietly changed
     * shape while everything still looks like it is working.
     *
     * @return array{spend_usd: float, runs: int, fallback_rate: float|null, avg_cost_usd: float|null}
     */
    public function summary(Carbon $since): array
    {
        return $this->remember('summary:'.$this->bucket($since), function () use ($since): array {
            $row = DB::table('analysis_runs')
                ->selectRaw('COUNT(*) AS runs, SUM(cost_usd) AS cost')
                ->selectRaw('SUM(CASE WHEN engine = ? THEN 1 ELSE 0 END) AS remote', [AnalysisEngine::OpenRouter->value])
                ->whereNotNull('finished_at')
                ->where('finished_at', '>=', $since)
                ->first();

            $runs = (int) ($row->runs ?? 0);
            $cost = round((float) ($row->cost ?? 0), 4);

            return [
                'spend_usd' => $cost,
                'runs' => $runs,
                // Null rather than 0 on an empty window: "0% fallback" and "no
                // runs at all" are very different states, and one of them means
                // the pipeline has stopped.
                'fallback_rate' => $runs === 0 ? null : round((int) $row->remote / $runs, 4),
                'avg_cost_usd' => $runs === 0 ? null : round($cost / $runs, 4),
            ];
        });
    }

    /**
     * @param  \Closure(): mixed  $callback
     */
    private function remember(string $key, \Closure $callback): mixed
    {
        return Cache::remember("analysis-costs:{$key}", self::TTL, $callback);
    }

    /**
     * A cache-key fragment for a window, bucketed to the minute.
     *
     * Keying on `$since->timestamp` looked right and cached NOTHING: the window
     * start is `now()->subHours(n)`, so the key changed every second and every
     * lookup was a miss plus a write into an unbounded key space. The widget
     * whose docblock promised caching was the one recomputing a 30-day
     * group-by on every poll.
     */
    private function bucket(Carbon $since): string
    {
        return $since->format('YmdHi');
    }
}
