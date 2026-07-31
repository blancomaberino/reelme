<?php

namespace App\Filament\Widgets;

use App\Enums\ShareStatus;
use App\Filament\Pages\Dashboard;
use App\Services\Observability\PipelineHealth;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The top-line answer to "is the pipeline healthy right now?" (T-107).
 *
 * Four numbers, chosen so that a healthy pipeline is boring and an unhealthy
 * one is obvious: what got through, what is stuck waiting on a human, what
 * broke, and whether work is piling up behind the workers.
 */
class PipelineHealthOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected ?string $heading = 'Pipeline health';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $health = app(PipelineHealth::class);
        $since = Dashboard::windowStart($this->pageFilters);
        $window = strtolower(Dashboard::windowLabel($this->pageFilters));

        $counts = $health->shareStatusCounts($since);
        $queue = $health->queueDepth();
        $oldest = $health->oldestRunningStageSeconds();

        // Anything not yet terminal is "in flight" — the operator cares that
        // work is moving, not which of the three transient states it is in.
        $inFlight = $counts[ShareStatus::Pending->value]
            + $counts[ShareStatus::Fetching->value]
            + $counts[ShareStatus::Analyzing->value];

        $ingested = array_sum($counts);
        $published = $counts[ShareStatus::Published->value];
        $failed = $counts[ShareStatus::Failed->value];
        $review = $counts[ShareStatus::Review->value];

        return [
            Stat::make('Shares ingested', number_format($ingested))
                ->description($window)
                ->color('gray'),

            Stat::make('Published', number_format($published))
                // The success rate is the number an operator actually reads;
                // the raw count alone says nothing without the denominator.
                ->description($ingested > 0
                    ? round($published / $ingested * 100).'% of ingested · '.number_format($inFlight).' still in flight'
                    : 'nothing ingested in this window')
                ->color($ingested > 0 && $published / $ingested < 0.5 ? 'warning' : 'success'),

            Stat::make('Failed', number_format($failed))
                ->description($review > 0
                    ? number_format($review).' more waiting on manual review'
                    : 'none waiting on manual review')
                ->color($failed > 0 ? 'danger' : 'success'),

            Stat::make('Queue depth', $queue['total'] === null ? '—' : number_format($queue['total']))
                ->description($this->queueDescription($queue['queues'], $oldest))
                ->color($this->queueColor($queue['total'], $oldest)),
        ];
    }

    /**
     * @param  array<string, int|null>  $queues
     */
    private function queueDescription(array $queues, ?int $oldestSeconds): string
    {
        if ($oldestSeconds !== null) {
            // A stage still marked `running` is either in progress or wedged;
            // its age is the one number that tells those apart.
            return 'oldest running stage: '.$this->humanizeSeconds($oldestSeconds);
        }

        $known = array_filter($queues, fn (?int $size) => $size !== null);
        if ($known === []) {
            return 'queue backend unreachable';
        }

        $busiest = array_search(max($known), $known, true);

        return max($known) > 0
            ? 'busiest: '.$busiest.' ('.number_format(max($known)).')'
            : 'no stage running, nothing queued';
    }

    private function queueColor(?int $total, ?int $oldestSeconds): string
    {
        if ($total === null) {
            return 'gray'; // unknown is not the same as healthy
        }
        // A stage running for over five minutes is past every job's timeout
        // budget in config/horizon.php — that is wedged, not slow.
        if ($oldestSeconds !== null && $oldestSeconds > 300) {
            return 'danger';
        }

        return $total > 100 ? 'warning' : 'success';
    }

    private function humanizeSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60).'m';
        }

        return intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m';
    }
}
