<?php

namespace App\Filament\Resources\Tags\Tables;

use App\Enums\TagKind;
use App\Jobs\TranslateTag;
use App\Models\Tag;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('places'))
            ->columns([
                TextColumn::make('name')->label('English')->searchable()->sortable(),
                TextColumn::make('name_i18n.es')->label('Spanish')->placeholder('— (falls back to English)'),
                TextColumn::make('kind')->badge()->sortable(),
                IconColumn::make('has_es')
                    ->label('Translated')
                    ->boolean()
                    ->state(fn (Tag $record): bool => filled($record->name_i18n['es'] ?? null)),
                TextColumn::make('places_count')->label('Places')->sortable(),
                TextColumn::make('slug')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('places_count', 'desc')
            ->filters([
                SelectFilter::make('kind')->options(TagKind::class),
                Filter::make('untranslated')
                    ->label('Missing Spanish label')
                    ->query(fn (Builder $query) => $query->whereRaw("name_i18n->>'es' is null")),
            ])
            ->recordActions([
                EditAction::make(),
                // Only where the job will actually act: a non-dish tag still
                // missing its Spanish label (the job skips dishes and set locales).
                // To fix a bad translation, edit the label directly.
                Action::make('translate')
                    ->label('Translate')
                    ->icon('heroicon-o-language')
                    ->visible(fn (Tag $record): bool => $record->kind !== TagKind::Dish && blank($record->name_i18n['es'] ?? null))
                    ->requiresConfirmation()
                    ->modalDescription('Queue a background AI translation for this tag.')
                    ->action(function (Tag $record): void {
                        TranslateTag::dispatch($record->id, config('ai.translate_locales'));
                        Notification::make()->title('Translation queued')->success()->send();
                    }),
            ]);
    }
}
