<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AnalysisCostByModel;
use App\Filament\Widgets\AnalysisCostChart;
use App\Filament\Widgets\AnalysisCostOverview;
use App\Filament\Widgets\PipelineFailureMix;
use App\Filament\Widgets\PipelineHealthOverview;
use App\Filament\Widgets\PipelineStageDurations;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * The admin landing page (T-107), replacing Filament's stock Dashboard.
 *
 * It answers one question at a glance — "is the pipeline healthy right now?" —
 * which previously required eyeballing the Shares table row by row. Everything
 * on it is read-only and derived from data the pipeline already writes;
 * alerting deliberately stays out of scope (that is T-052).
 *
 * The window select lives here rather than on each widget so all three read the
 * same period: a failure mix from the last hour next to stage timings from the
 * last week would invite exactly the wrong conclusion.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    /** Selectable windows, as `value => label`. Value is a whole number of hours. */
    public const WINDOWS = [
        '1' => 'Last hour',
        '24' => 'Last 24 hours',
        '168' => 'Last 7 days',
    ];

    public const DEFAULT_WINDOW = '24';

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('window')
                ->label('Time window')
                ->options(self::WINDOWS)
                ->default(self::DEFAULT_WINDOW)
                // No null option: every widget needs a bounded window, and an
                // unbounded aggregate over every share ever ingested is the one
                // query on this page that would not stay cheap.
                ->selectablePlaceholder(false),
        ]);
    }

    /**
     * Resolve a page-filter payload to the start of the window.
     *
     * Shared by the widgets so an unset, empty, or hand-tampered `window` query
     * parameter lands on the same default instead of each widget inventing its
     * own fallback (or dividing by a zero-hour window).
     *
     * @param  array<string, mixed>|null  $filters
     */
    public static function windowStart(?array $filters): Carbon
    {
        $value = (string) ($filters['window'] ?? self::DEFAULT_WINDOW);
        $hours = array_key_exists($value, self::WINDOWS) ? (int) $value : (int) self::DEFAULT_WINDOW;

        return now()->subHours($hours);
    }

    /**
     * The human label for the active window, so a widget can say what it is
     * showing without re-deriving the mapping.
     *
     * @param  array<string, mixed>|null  $filters
     */
    public static function windowLabel(?array $filters): string
    {
        $value = (string) ($filters['window'] ?? self::DEFAULT_WINDOW);

        return self::WINDOWS[$value] ?? self::WINDOWS[self::DEFAULT_WINDOW];
    }

    /**
     * The operating widgets, and only those.
     *
     * This deliberately drops Filament's stock AccountWidget and
     * FilamentInfoWidget from the landing page: the panel exists to operate the
     * pipeline, and "here is your avatar" is not what an operator opens it to
     * find out. Both stay registered on the panel for any page that wants them.
     *
     * NOTE: this list is EXPLICIT, so panel-level auto-discovery does not put a
     * widget here. A new widget that renders perfectly in its own Livewire test
     * and is not added below simply never appears on the dashboard — which is
     * indistinguishable from it not existing.
     *
     * @return array<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return [
            PipelineHealthOverview::class,
            PipelineStageDurations::class,
            PipelineFailureMix::class,
            // Cost (T-051). Below the pipeline widgets: an operator opens this
            // page to ask "is it working" before "what did it cost".
            AnalysisCostOverview::class,
            AnalysisCostChart::class,
            AnalysisCostByModel::class,
        ];
    }

    /**
     * @return int|array<string, int|null>
     */
    public function getColumns(): int|array
    {
        return 2;
    }
}
