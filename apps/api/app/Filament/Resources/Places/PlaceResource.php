<?php

namespace App\Filament\Resources\Places;

use App\Enums\PlaceStatus;
use App\Filament\Resources\Places\Pages\ListPlaces;
use App\Filament\Resources\Places\Pages\ViewPlace;
use App\Filament\Resources\Places\RelationManagers\SourcesRelationManager;
use App\Filament\Resources\Places\Schemas\PlaceInfolist;
use App\Filament\Resources\Places\Tables\PlacesTable;
use App\Models\Place;
use App\Services\Places\PlaceMerger;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The dedup/merge review queue (T-035): pending places await a human
 * "same restaurant?" decision — approve as new, merge into a candidate
 * duplicate, or hide. Places are created only by the pipeline, so the
 * resource has no create/edit; all mutations go through the page actions
 * (which delegate to {@see PlaceMerger}).
 */
class PlaceResource extends Resource
{
    protected static ?string $model = Place::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|\UnitEnum|null $navigationGroup = 'Moderation';

    public static function table(Table $table): Table
    {
        return PlacesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PlaceInfolist::configure($schema);
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Place::query()->where('status', PlaceStatus::Pending->value)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            SourcesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlaces::route('/'),
            'view' => ViewPlace::route('/{record}'),
        ];
    }
}
