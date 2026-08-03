<?php

namespace App\Filament\Resources\Offers;

use App\Enums\OfferStatus;
use App\Filament\Resources\Offers\Pages\ListOffers;
use App\Filament\Resources\Offers\Tables\OffersTable;
use App\Models\Offer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Post-hoc offer moderation (T-042, 06 §2.2).
 *
 * There is no pre-approval gate in v1 — operators publish directly — so this
 * page exists for exactly one power: pausing an offer that shouldn't be running
 * (misleading terms, a venue in dispute, a fee ceiling breached). Admins do not
 * create or edit offers here; the operator's own terms are what the diner
 * agreed to, and an admin quietly rewriting them would make the redemption
 * record unauditable.
 */
class OfferResource extends Resource
{
    protected static ?string $model = Offer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|\UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?string $recordTitleAttribute = 'title';

    public static function table(Table $table): Table
    {
        return OffersTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /** Archiving is the operator's DELETE; admins pause, they don't remove. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** Live offers first — the ones a pause would actually stop. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['place', 'createdBy']);
    }

    public static function getNavigationBadge(): ?string
    {
        $active = static::getModel()::query()->where('status', OfferStatus::Active)->count();

        return $active > 0 ? (string) $active : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOffers::route('/'),
        ];
    }
}
