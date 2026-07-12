<?php

namespace App\Filament\Resources\Places\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The place's provenance (read-only): which shares/posts vouch for this pin —
 * the context an admin needs to decide "same restaurant?" before a merge.
 */
class SourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'sources';

    public function isReadOnly(): bool
    {
        return true;
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
            ]);
    }
}
