<?php

namespace App\Filament\Resources\Shares\RelationManagers;

use App\Enums\AnalysisStatus;
use App\Filament\Resources\AnalysisRuns\AnalysisRunResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Every model attempt for this share, newest first (read-only). */
class AnalysisRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'analysisRuns';

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
                TextColumn::make('engine')->badge(),
                TextColumn::make('model'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AnalysisStatus $state): string => match ($state) {
                        AnalysisStatus::Succeeded => 'success',
                        AnalysisStatus::Failed => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('overall_confidence')->label('Confidence'),
                TextColumn::make('cost_usd')->label('Cost (USD)'),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('open')
                    ->url(fn ($record): string => AnalysisRunResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
