<?php

namespace App\Filament\Resources\Takedowns\Tables;

use App\Enums\TakedownStatus;
use App\Models\TakedownRequest;
use App\Models\User;
use App\Services\Moderation\ProcessTakedown;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TakedownRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('requester_name')->label('From')->searchable(),
                TextColumn::make('requester_role')->badge()->color('gray'),
                TextColumn::make('sourcePost.url')
                    ->label('Post')
                    ->placeholder('— unmatched —')
                    ->limit(50)
                    // Scheme-guarded: `target_url` is admin free-text, and
                    // Filament's url() validator lets `javascript:`-shaped
                    // input past FILTER_VALIDATE_URL.
                    ->url(fn (TakedownRequest $record): ?string => self::safeUrl($record->sourcePost->url ?? $record->target_url))
                    ->openUrlInNewTab(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (TakedownStatus $state): string => match ($state) {
                        TakedownStatus::Received => 'danger',
                        TakedownStatus::Processing => 'info',
                        TakedownStatus::CounterNotice => 'warning',
                        TakedownStatus::Actioned => 'success',
                        TakedownStatus::Closed => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('outcome_json')
                    ->label('Result')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?array $state): string => $state === null
                        ? '—'
                        : sprintf('%d shares, %d sources, %d media', $state['shares'] ?? 0, $state['place_sources'] ?? 0, $state['media'] ?? 0)),
                TextColumn::make('actionedBy.username')->label('By')->placeholder('—'),
                // Oldest first: a notice has a response clock, and newest-first
                // starves exactly the ones closest to running out of it.
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(TakedownStatus::cases())->pluck('value', 'value')->all())
                    ->default(TakedownStatus::Received->value),
            ])
            ->recordActions([
                self::processAction(),
                EditAction::make(),
            ]);
    }

    /**
     * Execute the takedown.
     *
     * Confirmation copy states what SURVIVES as prominently as what goes: the
     * place staying is the part an admin most needs to have understood before
     * pressing this, and the part a rightsholder will ask about.
     */
    private static function safeUrl(?string $url): ?string
    {
        return $url !== null && (str_starts_with($url, 'https://') || str_starts_with($url, 'http://'))
            ? $url
            : null;
    }

    private static function processAction(): Action
    {
        return Action::make('process')
            ->label('Action it')
            ->icon('heroicon-o-shield-exclamation')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Unpublishes every share citing this post, removes its place sources, and deletes the stored media. The PLACES stay on the map (FR-30) — a rightsholder is objecting to their footage, not to a restaurant existing.')
            ->visible(fn (TakedownRequest $record): bool => $record->status->isOpen())
            ->action(function (TakedownRequest $record): void {
                /** @var User $admin */
                $admin = auth()->user();

                $outcome = app(ProcessTakedown::class)->execute($record, $admin);

                // The SAME check the service branches on. Keying the UI on the
                // FK id while the service keys on the relation makes a dangling
                // FK report "actioned, 0 shares" as a success.
                if ($record->sourcePost === null) {
                    Notification::make()
                        ->warning()
                        ->title('Logged, but nothing to remove')
                        ->body('No post is matched to this notice, so nothing was unpublished. Match one and action it again if the notice is valid.')
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Takedown actioned')
                    ->body(sprintf(
                        '%d share(s) unpublished, %d source link(s) removed, %d media file(s) deleted. %d place(s) kept.',
                        $outcome['shares'],
                        $outcome['place_sources'],
                        $outcome['media'],
                        count($outcome['places_kept']),
                    ))
                    ->send();
            });
    }
}
