<?php

namespace App\Filament\Resources\Reviews\Tables;

use App\Models\Review;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('place.name')
                    ->label('Place')
                    ->searchable(),
                TextColumn::make('user.username')
                    ->label('Author')
                    ->searchable(),
                TextColumn::make('rating')
                    ->sortable(),
                TextColumn::make('body')
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('reports_count')
                    ->label('Reports')
                    ->sortable(),
                IconColumn::make('is_hidden')
                    ->label('Hidden')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('reports_count', 'desc')
            ->filters([
                // The moderation queue: anything a user flagged.
                Filter::make('reported')
                    ->label('Reported')
                    ->query(fn (Builder $query) => $query->has('reports')),
                TernaryFilter::make('is_hidden'),
            ])
            ->recordActions([
                self::hideAction(),
                self::unhideAction(),
            ]);
    }

    /** Hide = drop from every public surface + the rating.app aggregate. */
    private static function hideAction(): Action
    {
        return Action::make('hide')
            ->icon('heroicon-o-eye-slash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('The review disappears from the place and its rating aggregate. The author is not notified.')
            ->visible(fn (Review $record): bool => ! $record->is_hidden)
            ->action(function (Review $record): void {
                $record->is_hidden = true;
                $record->save();
            });
    }

    private static function unhideAction(): Action
    {
        return Action::make('unhide')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (Review $record): bool => $record->is_hidden)
            ->action(function (Review $record): void {
                $record->is_hidden = false;
                $record->save();
            });
    }
}
