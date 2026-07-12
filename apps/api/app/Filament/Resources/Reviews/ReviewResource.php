<?php

namespace App\Filament\Resources\Reviews;

use App\Filament\Resources\Reviews\Pages\ListReviews;
use App\Filament\Resources\Reviews\Tables\ReviewsTable;
use App\Models\Review;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Moderation queue for native reviews (T-059): reported/hidden reviews are
 * surfaced with hide/unhide actions. Read-only otherwise — admins never edit
 * a user's words.
 */
class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|\UnitEnum|null $navigationGroup = 'Moderation';

    public static function table(Table $table): Table
    {
        return ReviewsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['place', 'user'])
            ->withCount('reports');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviews::route('/'),
        ];
    }
}
