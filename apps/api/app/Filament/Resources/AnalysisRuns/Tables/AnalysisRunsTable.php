<?php

namespace App\Filament\Resources\AnalysisRuns\Tables;

use App\Enums\AnalysisEngine;
use App\Enums\AnalysisStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AnalysisRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('share_id')
                    ->label('Share'),
                TextColumn::make('engine')
                    ->badge(),
                TextColumn::make('model')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AnalysisStatus $state): string => match ($state) {
                        AnalysisStatus::Succeeded => 'success',
                        AnalysisStatus::Failed => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('overall_confidence')
                    ->label('Confidence')
                    ->sortable(),
                TextColumn::make('cost_usd')
                    ->label('Cost (USD)')
                    ->sortable(),
                TextColumn::make('input_tokens')
                    ->label('In tok')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('output_tokens')
                    ->label('Out tok')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('engine')
                    ->options(AnalysisEngine::class),
                SelectFilter::make('status')
                    ->options(AnalysisStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
