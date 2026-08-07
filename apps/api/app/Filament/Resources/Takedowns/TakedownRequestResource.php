<?php

namespace App\Filament\Resources\Takedowns;

use App\Filament\Resources\Takedowns\Pages\ListTakedownRequests;
use App\Filament\Resources\Takedowns\Schemas\TakedownRequestForm;
use App\Filament\Resources\Takedowns\Tables\TakedownRequestsTable;
use App\Models\TakedownRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Takedown / DMCA notices (T-049, IR-2 / R-07).
 *
 * Ops-entered from the `dmca@` inbox — creatable here, deliberately not over
 * the API. A self-service takedown endpoint would let anyone unpublish anyone
 * else's places by asserting a copyright claim; verifying the claim is the part
 * that needs a human.
 */
class TakedownRequestResource extends Resource
{
    protected static ?string $model = TakedownRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|\UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?string $navigationLabel = 'Takedowns';

    public static function form(Schema $schema): Schema
    {
        return TakedownRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TakedownRequestsTable::configure($table);
    }

    /** Unanswered notices. These carry a response clock. */
    public static function getNavigationBadge(): ?string
    {
        $open = TakedownRequest::query()
            ->whereIn('status', ['received', 'counter_notice'])
            ->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['sourcePost', 'actionedBy']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTakedownRequests::route('/'),
        ];
    }
}
