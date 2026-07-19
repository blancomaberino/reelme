<?php

namespace App\Filament\Resources\Shares\Tables;

use App\Enums\Platform;
use App\Enums\ShareStatus;
use App\Models\Share;
use App\Services\Moderation\ForceReprocessShare;
use App\Services\Moderation\ShareModerator;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class SharesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('user.username')
                    ->label('User')
                    ->searchable(),
                TextColumn::make('sourcePost.platform')
                    ->label('Platform')
                    ->badge(),
                TextColumn::make('sourcePost.url')
                    ->label('URL')
                    ->limit(50),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ShareStatus $state): string => match ($state) {
                        ShareStatus::Published => 'success',
                        ShareStatus::Review => 'warning',
                        ShareStatus::Failed, ShareStatus::Rejected => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('failure_reason')
                    ->placeholder('—'),
                TextColumn::make('review_reason')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(ShareStatus::class),
                SelectFilter::make('platform')
                    ->options(Platform::class)
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('sourcePost', fn ($q) => $q->where('platform', $data['value']))
                        : $query),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('forceReprocess')
                    ->label('Force reprocess')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Re-run extraction from scratch (re-invokes the LLM). Existing pins for this share are cleared and rebuilt.')
                    ->action(function (Share $record): void {
                        app(ForceReprocessShare::class)->run($record);
                        Notification::make()->success()->title('Reprocess queued')->send();
                    }),
                Action::make('takeDown')
                    ->label('Take down')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Unpublish this share (removes its feed card, and its map pin when no one else published it). Reversible via re-share or reprocess.')
                    ->action(function (Share $record): void {
                        app(ShareModerator::class)->takeDown($record);
                        Notification::make()->success()->title('Share taken down')->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('forceReprocess')
                        ->label('Force reprocess')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $service = app(ForceReprocessShare::class);
                            foreach ($records as $record) {
                                $service->run($record);
                            }
                            Notification::make()->success()->title("Reprocess queued for {$records->count()} share(s)")->send();
                        }),
                    BulkAction::make('takeDown')
                        ->label('Take down')
                        ->icon('heroicon-o-eye-slash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $service = app(ShareModerator::class);
                            foreach ($records as $record) {
                                $service->takeDown($record);
                            }
                            Notification::make()->success()->title("Took down {$records->count()} share(s)")->send();
                        }),
                ]),
            ]);
    }
}
