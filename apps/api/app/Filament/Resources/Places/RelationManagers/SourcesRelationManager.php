<?php

namespace App\Filament\Resources\Places\RelationManagers;

use App\Models\PlaceSource;
use App\Services\Moderation\ForceReprocessShare;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The place's provenance (read-only): which shares/posts vouch for this pin —
 * the context an admin needs to decide "same restaurant?" before a merge. The
 * one mutating affordance is Reprocess (T-072): re-ingest + re-run the source's
 * whole share through the pipeline when its extraction came out wrong.
 */
class SourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'sources';

    public function isReadOnly(): bool
    {
        return false; // no CRUD actions are defined; the only action is Reprocess
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('share_id')
                    ->label('Share'),
                TextColumn::make('sourcePost.url')
                    ->label('Source post')
                    ->limit(60)
                    ->url(fn ($record): ?string => $record->sourcePost?->url, shouldOpenInNewTab: true),
                TextColumn::make('extraction_snapshot_json.name')
                    ->label('Extracted name'),
                TextColumn::make('analysisRun.overall_confidence')
                    ->label('Confidence'),
                IconColumn::make('is_primary')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime(),
            ])
            ->recordActions([
                Action::make('reprocess')
                    ->label('Reprocess')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Re-ingest and re-run the whole post (share) through the pipeline from a fresh extraction. This share\'s existing pins are cleared and rebuilt — it may re-map to a different place.')
                    ->visible(fn (PlaceSource $record): bool => $record->share !== null)
                    ->action(function (PlaceSource $record): void {
                        app(ForceReprocessShare::class)->run($record->share);
                        Notification::make()->success()->title('Reprocess queued')->send();
                    }),
            ]);
    }
}
