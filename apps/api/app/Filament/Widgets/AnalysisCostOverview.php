<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Services\Observability\AnalysisCosts;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What analysis is costing (T-051, NFR-13, 04 §8).
 *
 * Three numbers, because three is what an operator can hold: what we spent,
 * how much of it went to the paid engine, and what a single run costs on
 * average. The fallback rate is the one to watch — 04 §8 treats a sustained
 * rate above 30% as a warning, because it means local inference is failing or
 * refusing and the cost curve has quietly changed shape while everything still
 * looks like it is working.
 */
class AnalysisCostOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Analysis cost';

    protected int|string|array $columnSpan = 'full';

    /**
     * Never. StatsOverviewWidget polls every 5s by default, which would re-run
     * the aggregate twelve times a minute for numbers that move on a scale of
     * hours — and make the database slower the more closely it is watched.
     */
    protected ?string $pollingInterval = null;

    /** 04 §8: sustained remote fallback above this is a problem, not a blip. */
    private const FALLBACK_WARNING = 0.30;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $summary = app(AnalysisCosts::class)->summary(Dashboard::windowStart($this->pageFilters));
        $window = strtolower(Dashboard::windowLabel($this->pageFilters));

        $fallback = $summary['fallback_rate'];

        return [
            Stat::make('Spend', '$'.number_format($summary['spend_usd'], 2))
                // `windowLabel()` already reads "last 24 hours" — prefixing it
                // with "in the last" produced "in the last last 24 hours",
                // which no test could see and the browser showed immediately.
                ->description("{$summary['runs']} runs · {$window}")
                ->color($summary['spend_usd'] > 0 ? 'primary' : 'gray'),

            Stat::make(
                'Remote fallback',
                // "No runs" is not "0% fallback" — one of those means the
                // pipeline has stopped, and a confident 0% would hide it.
                $fallback === null ? '—' : number_format($fallback * 100, 1).'%',
            )
                ->description($fallback === null
                    ? 'No analysis runs in this window'
                    : 'Share of runs that went to the paid engine')
                ->color(match (true) {
                    $fallback === null => 'gray',
                    $fallback >= self::FALLBACK_WARNING => 'danger',
                    default => 'success',
                }),

            Stat::make(
                'Avg per run',
                $summary['avg_cost_usd'] === null ? '—' : '$'.number_format($summary['avg_cost_usd'], 4),
            )
                ->description('Across every engine')
                ->color('gray'),
        ];
    }
}
