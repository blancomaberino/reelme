<?php

namespace App\Filament\Resources\InfluencerClaims;

use App\Filament\Resources\InfluencerClaims\Pages\ListInfluencerClaims;
use App\Filament\Resources\InfluencerClaims\Tables\InfluencerClaimsTable;
use App\Models\InfluencerClaim;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Influencer claim review + dispute resolution (T-038, 06 §5.1). Admins reject
 * bogus claims (notifying the claimant) and override the owner of a disputed
 * identity. Verification itself happens through the API; this panel handles the
 * manual disposition the flow escalates.
 */
class InfluencerClaimResource extends Resource
{
    protected static ?string $model = InfluencerClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|\UnitEnum|null $navigationGroup = 'Users & Access';

    protected static ?string $recordTitleAttribute = 'token';

    public static function table(Table $table): Table
    {
        return InfluencerClaimsTable::configure($table);
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
        return parent::getEloquentQuery()->with(['influencer', 'user']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInfluencerClaims::route('/'),
        ];
    }
}
