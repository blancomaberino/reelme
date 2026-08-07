<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Services\Observability\AnalysisCosts;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Where the money went, by model and by account (T-051, NFR-13).
 *
 * Two tables because they answer two different questions an operator has in
 * sequence: "which model is expensive" tells you what to change, and "who is
 * spending" tells you whether the answer is a model at all or one runaway
 * account. Showing only the first would send someone tuning prompts when the
 * real story is a single user with a script.
 */
class AnalysisCostByModel extends Widget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 6;

    protected string $view = 'filament.widgets.analysis-cost-by-model';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $costs = app(AnalysisCosts::class);
        $since = Dashboard::windowStart($this->pageFilters);

        return [
            'models' => $costs->byModel($since),
            'spenders' => $costs->topSpenders($since),
            'window' => strtolower(Dashboard::windowLabel($this->pageFilters)),
        ];
    }
}
