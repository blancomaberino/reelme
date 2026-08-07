<?php

namespace App\Filament\Resources\Reports;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\Tables\ReportsTable;
use App\Models\Report;
use App\Models\Share;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The moderation queue (T-049).
 *
 * Read-only over the reports themselves — an admin decides, they do not edit
 * what a user wrote. Everything actionable is a row action with a required
 * note, so every decision leaves a reason behind it.
 *
 * The badge is the point of the whole resource: an app-store reviewer, and
 * anyone on call, needs "how many complaints are unhandled" visible without
 * opening anything.
 */
class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|\UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?int $navigationSort = -1;

    public static function table(Table $table): Table
    {
        return ReportsTable::configure($table);
    }

    /** Unhandled reports. Red once anything urgent is waiting. */
    public static function getNavigationBadge(): ?string
    {
        $open = Report::query()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return Report::query()->open()->whereIn('reason', collect(ReportReason::urgent())->pluck('value'))->exists()
            ? 'danger'
            : 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        // The reportable is morphed, so eager-loading it is the difference
        // between one query and one per row on a queue that is meant to be
        // scanned quickly. `morphWith` reaches the share's author too — the
        // triage line renders "share #12 by @someone", which otherwise lazy
        // loads a user per row on top of that.
        return parent::getEloquentQuery()
            ->with([
                'reporter',
                'resolver',
                // Typed as the base Relation for PHPStan's benefit; morphWith
                // only exists on MorphTo, which is what this relation is.
                'reportable' => function (Relation $morph): void {
                    /** @var MorphTo<Model, Report> $morph */
                    $morph->morphWith([Share::class => ['user']]);
                },
            ])
            ->selectRaw('reports.*, (select count(*) from reports peers'
                .' where peers.reportable_type = reports.reportable_type'
                .' and peers.reportable_id = reports.reportable_id) as same_target_count');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
        ];
    }

    /**
     * Reports still needing a decision, for the default view.
     *
     * @return list<string>
     */
    public static function openStatuses(): array
    {
        return [ReportStatus::Open->value, ReportStatus::Reviewing->value];
    }
}
