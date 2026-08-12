<?php

namespace App\Filament\Resources\PlaceEditSuggestions;

use App\Enums\SuggestionStatus;
use App\Filament\Resources\PlaceEditSuggestions\Pages\ListPlaceEditSuggestions;
use App\Filament\Resources\PlaceEditSuggestions\Tables\PlaceEditSuggestionsTable;
use App\Models\PlaceEditSuggestion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The suggested-edit queue (T-083).
 *
 * A read-and-decide surface: rows arrive from the app and leave approved or
 * rejected. There is no create or edit form on purpose — a moderator who could
 * hand-write a suggestion would be editing the place through a second door,
 * bypassing the audit trail that the Places resource already provides.
 */
class PlaceEditSuggestionResource extends Resource
{
    protected static ?string $model = PlaceEditSuggestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?string $navigationLabel = 'Suggested edits';

    /** The pending queue is the point of the page — badge it. */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::query()->where('status', SuggestionStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return PlaceEditSuggestionsTable::configure($table);
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['place', 'user']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlaceEditSuggestions::route('/'),
        ];
    }
}
