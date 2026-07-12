<?php

namespace App\Filament\Resources\Shares\Tables;

use App\Enums\Platform;
use App\Enums\ShareStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
            ]);
    }
}
