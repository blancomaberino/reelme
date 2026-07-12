<?php

namespace App\Filament\Resources\Shares;

use App\Filament\Resources\Shares\Pages\ListShares;
use App\Filament\Resources\Shares\Pages\ViewShare;
use App\Filament\Resources\Shares\RelationManagers\AnalysisRunsRelationManager;
use App\Filament\Resources\Shares\Schemas\ShareInfolist;
use App\Filament\Resources\Shares\Tables\SharesTable;
use App\Models\Share;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only pipeline debugging surface (T-035): what did a user share, where
 * did it stall, and why. Mutations happen through the pipeline and the user
 * review API only — admins observe.
 */
class ShareResource extends Resource
{
    protected static ?string $model = Share::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static string|\UnitEnum|null $navigationGroup = 'Pipeline';

    public static function table(Table $table): Table
    {
        return SharesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ShareInfolist::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'sourcePost']);
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

    public static function getRelations(): array
    {
        return [
            AnalysisRunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShares::route('/'),
            'view' => ViewShare::route('/{record}'),
        ];
    }
}
