<?php

namespace App\Filament\Widgets;

use App\Services\Observability\AnalysisCosts;
use Filament\Widgets\ChartWidget;

/**
 * Daily analysis spend, one line per engine (T-051, NFR-13).
 *
 * Fixed at 30 days rather than following the dashboard's window filter: the
 * thing this chart is for is noticing a trend — a slow climb, or the step
 * change when local inference started falling back — and those are invisible
 * inside a 24-hour view.
 */
class AnalysisCostChart extends ChartWidget
{
    protected static ?int $sort = 5;

    protected ?string $heading = 'Analysis cost — last 30 days';

    protected ?string $description = 'Daily spend per engine. Local runs cost nothing; a rising remote line is the bill.';

    protected int|string|array $columnSpan = 'full';

    /**
     * Never. The underlying query is cached for a minute anyway, and a chart
     * that polls is a chart that makes the database slower the more closely it
     * is being watched.
     */
    protected ?string $pollingInterval = null;

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $data = app(AnalysisCosts::class)->dailyByEngine(30);

        return [
            'labels' => $data['labels'],
            'datasets' => collect($data['series'])->map(fn (array $points, string $engine) => [
                'label' => $engine,
                'data' => $points,
                'borderColor' => $engine === 'openrouter' ? '#B2391F' : '#377245',
                'backgroundColor' => $engine === 'openrouter' ? '#F9E3DB' : '#E2EFE3',
                'fill' => true,
                'tension' => 0.3,
            ])->values()->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
