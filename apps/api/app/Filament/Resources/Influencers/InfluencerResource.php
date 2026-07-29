<?php

namespace App\Filament\Resources\Influencers;

use App\Filament\Resources\Influencers\Pages\ListInfluencers;
use App\Filament\Resources\Influencers\Tables\InfluencersTable;
use App\Models\Influencer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Influencer identity directory (T-038). Auto-created from ingested post authors
 * (never hand-created), so read-only — but browsable/filterable, and the surface
 * where an admin manually assigns/reassigns/releases a claim (the interim tool
 * until Instagram OAuth is live).
 */
class InfluencerResource extends Resource
{
    protected static ?string $model = Influencer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|\UnitEnum|null $navigationGroup = 'Users & Access';

    protected static ?string $recordTitleAttribute = 'handle';

    public static function table(Table $table): Table
    {
        return InfluencersTable::configure($table);
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
        return parent::getEloquentQuery()
            ->with('claimedBy')
            ->withCount(['claims as pending_claims_count' => fn (Builder $q) => $q->where('status', 'pending')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInfluencers::route('/'),
        ];
    }
}
