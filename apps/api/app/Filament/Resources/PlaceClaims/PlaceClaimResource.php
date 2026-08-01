<?php

namespace App\Filament\Resources\PlaceClaims;

use App\Enums\ClaimStatus;
use App\Filament\Resources\PlaceClaims\Pages\ListPlaceClaims;
use App\Filament\Resources\PlaceClaims\Tables\PlaceClaimsTable;
use App\Models\PlaceClaim;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Restaurant-owner claim review (T-041, 06 §2.1).
 *
 * The phone and website methods settle themselves, so what reaches a human here
 * is the `document` queue plus any dispute the automatic paths could not close.
 * Approving grants a real capability — offer creation and, downstream, fees
 * drawn against the venue — so the actions are deliberately confirm-gated.
 */
class PlaceClaimResource extends Resource
{
    protected static ?string $model = PlaceClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|\UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?string $navigationLabel = 'Place Claims';

    // No $recordTitleAttribute, mirroring InfluencerClaimResource: the per-row
    // state is `evidence_json`, which holds a hashed OTP and a live token.

    /** The pending queue is the point of the page — badge it. */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::query()->where('status', ClaimStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return PlaceClaimsTable::configure($table);
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
            'index' => ListPlaceClaims::route('/'),
        ];
    }
}
