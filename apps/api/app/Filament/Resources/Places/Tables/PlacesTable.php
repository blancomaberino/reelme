<?php

namespace App\Filament\Resources\Places\Tables;

use App\Enums\PlaceStatus;
use App\Models\Place;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlacesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->description(fn ($record): ?string => $record->address_line1),
                TextColumn::make('city')
                    ->searchable(),
                TextColumn::make('country_code')
                    ->label('Country'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PlaceStatus $state): string => match ($state) {
                        PlaceStatus::Pending => 'warning',
                        PlaceStatus::Active => 'success',
                        PlaceStatus::Merged => 'gray',
                        PlaceStatus::Hidden => 'danger',
                    }),
                TextColumn::make('shares_count')
                    ->label('Sources')
                    ->sortable(),
                TextColumn::make('avg_extraction_confidence')
                    ->label('Avg conf.')
                    ->numeric(2)
                    ->sortable(),
                IconColumn::make('google_place_id')
                    ->label('Google')
                    ->boolean()
                    ->state(fn ($record): bool => $record->google_place_id !== null),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                // The T-035 review queue: pending places awaiting a human
                // same-restaurant decision. On by default; clear it to browse all.
                Filter::make('review_queue')
                    ->label('Review queue (pending)')
                    ->query(fn (Builder $query) => $query->where('status', PlaceStatus::Pending->value))
                    ->default(),
                SelectFilter::make('status')
                    ->options(PlaceStatus::class),
                SelectFilter::make('country_code')
                    ->label('Country')
                    ->options(fn (): array => Place::query()
                        ->distinct()->orderBy('country_code')->pluck('country_code', 'country_code')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
