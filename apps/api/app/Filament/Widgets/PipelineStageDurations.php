<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Services\Observability\PipelineHealth;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Per-stage latency and failure rate, in pipeline order (T-107).
 *
 * Read top to bottom it is the path a share takes, so the stage where things
 * slow down or break is the row your eye stops on. p95 sits next to p50 because
 * the gap between them is the actual signal: a stage whose median is fine and
 * whose tail is minutes is a stage with a failure mode, not a slow stage.
 */
class PipelineStageDurations extends Widget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected string $view = 'filament.widgets.pipeline-stage-durations';

    protected int|string|array $columnSpan = 1;

    /**
     * @return array{rows: list<array{stage: string, runs: int, failed: int, failureRate: float, p50: int|null, p95: int|null}>, hasData: bool, window: string}
     */
    protected function getViewData(): array
    {
        $health = app(PipelineHealth::class);
        $since = Dashboard::windowStart($this->pageFilters);

        return [
            'rows' => $health->stageDurations($since),
            // Distinguishes "no stage ran" from "seven stages each ran zero
            // times" — the table renders every stage either way, so without
            // this the empty state looks like a broken pipeline.
            'hasData' => $health->stageRunCount($since) > 0,
            'window' => strtolower(Dashboard::windowLabel($this->pageFilters)),
        ];
    }
}
