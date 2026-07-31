<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Services\Observability\PipelineHealth;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Which failure to chase first (T-107) — failure codes by frequency, not just
 * a total. `fetch_unavailable` dominating means an adapter is broken;
 * `geocode_failed` dominating means a provider is; the same total count means
 * something completely different in each case.
 *
 * `review` shares are counted alongside `failed` ones: a share parked for
 * manual review carries the same diagnostic `failure_reason`, and excluding it
 * would hide the recoverable half of the problem.
 */
class PipelineFailureMix extends Widget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected string $view = 'filament.widgets.pipeline-failure-mix';

    protected int|string|array $columnSpan = 1;

    /** How many distinct codes to list before the tail stops being actionable. */
    private const TOP_N = 8;

    /**
     * @return array{rows: list<array{reason: string, total: int, share: float}>, window: string}
     */
    protected function getViewData(): array
    {
        $health = app(PipelineHealth::class);
        $since = Dashboard::windowStart($this->pageFilters);

        $rows = $health->failureMix($since, self::TOP_N);
        // The bar is drawn relative to the biggest code, not to the total: this
        // is a ranking, and scaling to the total makes every bar unreadably
        // short as soon as the failures are spread across many codes.
        // `failureMix` returns them already sorted, so the first row IS the max.
        $largest = $rows[0]['total'] ?? 0;

        return [
            'rows' => array_map(fn (array $row) => [
                ...$row,
                'share' => $largest > 0 ? round($row['total'] / $largest * 100, 1) : 0.0,
            ], $rows),
            'window' => strtolower(Dashboard::windowLabel($this->pageFilters)),
        ];
    }
}
