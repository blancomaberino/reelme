<?php

namespace App\Filament\Resources\AnalysisRuns;

use App\Filament\Resources\AnalysisRuns\Pages\ListAnalysisRuns;
use App\Filament\Resources\AnalysisRuns\Pages\ViewAnalysisRun;
use App\Filament\Resources\AnalysisRuns\Schemas\AnalysisRunInfolist;
use App\Filament\Resources\AnalysisRuns\Tables\AnalysisRunsTable;
use App\Models\AnalysisRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only AI-run debugging surface (T-035): which engine/model ran, what it
 * cost, what it returned. Rows are written only by the pipeline (T-019+).
 */
class AnalysisRunResource extends Resource
{
    protected static ?string $model = AnalysisRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|\UnitEnum|null $navigationGroup = 'Pipeline';

    public static function table(Table $table): Table
    {
        return AnalysisRunsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AnalysisRunInfolist::configure($schema);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnalysisRuns::route('/'),
            'view' => ViewAnalysisRun::route('/{record}'),
        ];
    }
}
