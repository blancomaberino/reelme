<?php

namespace App\Filament\Resources\AnalysisRuns\Schemas;

use App\Enums\AnalysisStatus;
use App\Models\AnalysisRun;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Phiki\Grammar\Grammar;

class AnalysisRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Run')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('id'),
                        TextEntry::make('share_id')->label('Share'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (AnalysisStatus $state): string => match ($state) {
                                AnalysisStatus::Succeeded => 'success',
                                AnalysisStatus::Failed => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('engine')->badge(),
                        TextEntry::make('model'),
                        TextEntry::make('prompt_version')->placeholder('—'),
                        TextEntry::make('overall_confidence')->label('Confidence')->placeholder('—'),
                        TextEntry::make('cost_usd')->label('Cost (USD)')->placeholder('—'),
                        TextEntry::make('input_tokens')->placeholder('—'),
                        TextEntry::make('output_tokens')->placeholder('—'),
                        TextEntry::make('started_at')->dateTime()->placeholder('—'),
                        TextEntry::make('finished_at')->dateTime()->placeholder('—'),
                    ]),
                Section::make('Result')
                    ->schema([
                        CodeEntry::make('result_json')
                            ->hiddenLabel()
                            ->grammar(Grammar::Json),
                    ])
                    ->visible(fn (AnalysisRun $record): bool => $record->result_json !== null)
                    ->collapsible(),
                Section::make('Error')
                    ->schema([
                        TextEntry::make('error')
                            ->hiddenLabel()
                            ->color('danger'),
                    ])
                    ->visible(fn (AnalysisRun $record): bool => $record->error !== null),
            ]);
    }
}
