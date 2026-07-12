<?php

namespace App\Filament\Resources\Shares\Schemas;

use App\Enums\ShareStatus;
use App\Models\Share;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Phiki\Grammar\Grammar;

class ShareInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Share')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('id'),
                        TextEntry::make('user.username')->label('User'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (ShareStatus $state): string => match ($state) {
                                ShareStatus::Published => 'success',
                                ShareStatus::Review => 'warning',
                                ShareStatus::Failed, ShareStatus::Rejected => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('sourcePost.platform')->label('Platform')->badge(),
                        TextEntry::make('sourcePost.url')
                            ->label('Source URL')
                            ->url(fn (Share $record): ?string => $record->sourcePost?->url, shouldOpenInNewTab: true)
                            ->copyable(),
                        TextEntry::make('shared_via')->placeholder('—'),
                        TextEntry::make('failure_reason')->placeholder('—'),
                        TextEntry::make('review_reason')->placeholder('—'),
                        TextEntry::make('published_at')->dateTime()->placeholder('—'),
                        TextEntry::make('created_at')->dateTime(),
                        TextEntry::make('updated_at')->dateTime(),
                    ]),
                Section::make('Caption')
                    ->schema([
                        TextEntry::make('sourcePost.caption')
                            ->hiddenLabel()
                            ->placeholder('—'),
                    ])
                    ->collapsible(),
                Section::make('Corrected extraction (user review)')
                    ->schema([
                        CodeEntry::make('corrected_extraction_json')
                            ->hiddenLabel()
                            ->grammar(Grammar::Json),
                    ])
                    ->visible(fn (Share $record): bool => $record->corrected_extraction_json !== null)
                    ->collapsible(),
            ]);
    }
}
