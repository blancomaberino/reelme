<?php

namespace App\Filament\Resources\Offers\Tables;

use App\Enums\OfferDiscountType;
use App\Enums\OfferStatus;
use App\Models\Offer;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OffersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->description(fn (Offer $record): ?string => $record->description),
                TextColumn::make('place.name')
                    ->label('Place')
                    ->searchable()
                    // Two venues can share a name; the moderator needs to know
                    // which one they are switching off.
                    ->description(fn (Offer $record): ?string => collect([
                        $record->place?->city,
                        $record->place?->country_code,
                    ])->filter()->implode(', ') ?: null),
                TextColumn::make('discount_value')
                    ->label('Discount')
                    // One integer in three units — rendered, never shown raw,
                    // so "500" can't be read as 500% off.
                    ->formatStateUsing(fn (Offer $record): string => match ($record->discount_type) {
                        OfferDiscountType::Percent => "{$record->discount_value}%",
                        OfferDiscountType::FixedAmount => number_format($record->discount_value / 100, 2).' (minor: '.$record->discount_value.')',
                        OfferDiscountType::FreeItem => $record->discount_value.'× free item',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (OfferStatus $state): string => match ($state) {
                        OfferStatus::Active => 'success',
                        OfferStatus::Draft => 'gray',
                        OfferStatus::Paused => 'warning',
                        OfferStatus::Expired, OfferStatus::Archived => 'danger',
                    }),
                TextColumn::make('redemptions_count')->label('Redeemed')->sortable(),
                TextColumn::make('quota_total')->label('Quota')->placeholder('∞')->toggleable(),
                TextColumn::make('starts_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('ends_at')->dateTime()->placeholder('open-ended')->sortable(),
                TextColumn::make('createdBy.username')->label('Operator')->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(OfferStatus::class),
                SelectFilter::make('discount_type')->options(OfferDiscountType::class),
            ])
            ->recordActions([
                Action::make('pause')
                    ->label('Pause')
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(
                        'Pause this offer. It disappears from diner browse immediately and no new redemptions can be '
                        .'issued. Already-issued redemptions are unaffected — they remain valid and billable.'
                    )
                    ->visible(fn (Offer $record): bool => $record->status === OfferStatus::Active)
                    ->action(function (Offer $record): void {
                        $record->status = OfferStatus::Paused;
                        $record->save();
                        Notification::make()->success()->title('Offer paused')->send();
                    }),
                // The undo. Without it a mistaken pause could only be reversed by
                // the operator, who has no way to know an admin did it.
                Action::make('resume')
                    ->label('Resume')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Resume this offer. It becomes visible to diners again immediately.')
                    // Hidden once the window has closed. Resuming there would set
                    // `status = active`, which is what the diner browse filters
                    // on — so an ended promotion would reappear in the list while
                    // `is_redeemable` said false. Re-run it by editing the dates
                    // instead, which is the operator's call, not a moderator's.
                    ->visible(fn (Offer $record): bool => $record->status === OfferStatus::Paused
                        && ($record->ends_at === null || ! $record->ends_at->isPast()))
                    ->action(function (Offer $record): void {
                        $record->status = OfferStatus::Active;
                        $record->save();
                        Notification::make()->success()->title('Offer resumed')->send();
                    }),
            ]);
    }
}
