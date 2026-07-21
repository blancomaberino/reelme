<?php

namespace App\Filament\Resources\Places\Tables;

use App\Enums\PlaceStatus;
use App\Models\Place;
use App\Services\Moderation\PlaceModerator;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
                        PlaceStatus::Removed => 'gray',
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
                IconColumn::make('needs_admin_review')
                    ->label('Needs review')
                    ->boolean()
                    ->trueIcon('heroicon-o-flag')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-minus'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                // The T-035 review queue: pending places awaiting a human
                // same-restaurant decision. An OPTIONAL filter (not on by default) —
                // defaulting it on hid every active place and every Removed one, so
                // the list looked empty and taken-down places couldn't be found to
                // Restore. Browse all statuses by default; toggle this for the queue.
                Filter::make('review_queue')
                    ->label('Review queue (pending)')
                    ->query(fn (Builder $query) => $query->where('status', PlaceStatus::Pending->value)),
                // Confirm-before-publish (T-098): places published as a best guess
                // (the sharer skipped/abandoned the confirm) — live on the map but
                // flagged for an admin to verify and clear. The owner-facing chore
                // became an admin queue.
                Filter::make('needs_admin_review')
                    ->label('Needs admin review')
                    ->query(fn (Builder $query) => $query->where('needs_admin_review', true)),
                SelectFilter::make('status')
                    ->options(PlaceStatus::class),
                SelectFilter::make('country_code')
                    ->label('Country')
                    ->options(fn (): array => Place::query()
                        ->distinct()->orderBy('country_code')->pluck('country_code', 'country_code')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('takeDown')
                    ->label('Hide')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The place disappears from the map, browse, search and every feed/profile card. Its sources are kept. Reversible with Restore.')
                    ->visible(fn (Place $record): bool => in_array($record->status, [PlaceStatus::Pending, PlaceStatus::Active], true))
                    ->action(function (Place $record): void {
                        app(PlaceModerator::class)->takeDown([$record]);
                        Notification::make()->success()->title('Place hidden')->send();
                    }),
                Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Place $record): bool => $record->status === PlaceStatus::Hidden)
                    ->action(function (Place $record): void {
                        app(PlaceModerator::class)->restore([$record]);
                        Notification::make()->success()->title('Place restored to the review queue')->send();
                    }),
                Action::make('markReviewed')
                    ->label('Mark reviewed')
                    ->icon('heroicon-o-check-circle')
                    ->color('gray')
                    ->visible(fn (Place $record): bool => $record->needs_admin_review)
                    ->action(function (Place $record): void {
                        // Direct assignment — needs_admin_review is a system flag, not
                        // in $fillable, so ->update() would silently no-op.
                        $record->needs_admin_review = false;
                        $record->save();
                        Notification::make()->success()->title('Cleared from the review queue')->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('takeDown')
                        ->label('Hide')
                        ->icon('heroicon-o-eye-slash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            app(PlaceModerator::class)->takeDown($records);
                            Notification::make()->success()->title("Hid {$records->count()} place(s)")->send();
                        }),
                    BulkAction::make('restore')
                        ->label('Restore')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('success')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            app(PlaceModerator::class)->restore($records);
                            Notification::make()->success()->title("Restored {$records->count()} place(s)")->send();
                        }),
                    BulkAction::make('markReviewed')
                        ->label('Mark reviewed')
                        ->icon('heroicon-o-check-circle')
                        ->color('gray')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $cleared = Place::whereKey($records->pluck('id'))->update(['needs_admin_review' => false]);
                            Notification::make()->success()->title("Cleared {$cleared} place(s) from the review queue")->send();
                        }),
                ]),
            ]);
    }
}
