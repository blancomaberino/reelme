<?php

namespace App\Filament\Resources\Reports;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\Tables\ReportsTable;
use App\Models\Report;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
        // scanned quickly.
        return parent::getEloquentQuery()->with(['reporter', 'resolver', 'reportable']);
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
