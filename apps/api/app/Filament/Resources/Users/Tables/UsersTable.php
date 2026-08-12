<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Support\Countries;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('username')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                // The name, not the code: an admin scanning the list should not
                // have to know that BQ is Bonaire. Sorting stays on the stored
                // code, which is what the column actually holds.
                TextColumn::make('country_code')
                    ->label('Country')
                    // `placeholder`, not a `??` inside the formatter: Filament
                    // short-circuits to the placeholder when the state is blank
                    // and never calls formatStateUsing, so a fallback in there
                    // would be dead code for every user with no country.
                    ->formatStateUsing(fn (string $state): string => Countries::name($state, config('app.locale')) ?? $state)
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean(),
                IconColumn::make('is_influencer')
                    ->label('Influencer')
                    ->boolean(),
                IconColumn::make('is_restaurant_owner')
                    ->label('Restaurant')
                    ->boolean(),
                IconColumn::make('is_public')
                    ->label('Public')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_admin'),
                TernaryFilter::make('is_influencer'),
                TernaryFilter::make('is_restaurant_owner'),
                // Banned = soft-deleted. Toggle to view/restore banned accounts.
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                self::banAction(),
                self::unbanAction(),
            ]);
    }

    /**
     * Ban = revoke all Sanctum tokens + soft delete. Hidden for already-banned
     * rows and disabled for the current admin (no self-ban).
     */
    private static function banAction(): Action
    {
        return Action::make('ban')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription("This revokes the user's API tokens and hides their account. Their username/email stay reserved.")
            ->visible(fn (User $record): bool => ! $record->trashed())
            ->disabled(fn (User $record): bool => $record->is(auth()->user()))
            ->action(function (User $record): void {
                $record->tokens()->delete();
                $record->delete();
            });
    }

    private static function unbanAction(): Action
    {
        return Action::make('unban')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (User $record): bool => $record->trashed())
            ->action(fn (User $record) => $record->restore());
    }
}
