<?php

namespace App\Filament\Resources\Places\Schemas;

use App\Enums\PlaceStatus;
use App\Filament\Resources\Places\PlaceResource;
use App\Models\Place;
use App\Services\Places\PlaceResolver;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PlaceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Place')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (PlaceStatus $state): string => match ($state) {
                                PlaceStatus::Pending => 'warning',
                                PlaceStatus::Active => 'success',
                                PlaceStatus::Merged => 'gray',
                                PlaceStatus::Removed => 'gray',
                                PlaceStatus::Hidden => 'danger',
                            }),
                        TextEntry::make('mergedInto.name')
                            ->label('Merged into')
                            ->url(fn (Place $record): ?string => $record->merged_into_place_id !== null
                                ? PlaceResource::getUrl('view', ['record' => $record->merged_into_place_id])
                                : null)
                            ->color('primary')
                            ->visible(fn (Place $record): bool => $record->merged_into_place_id !== null),
                        TextEntry::make('slug')->copyable(),
                        TextEntry::make('address_line1')->label('Address')->placeholder('—'),
                        TextEntry::make('city')->placeholder('—'),
                        TextEntry::make('region')->placeholder('—'),
                        TextEntry::make('postal_code')->placeholder('—'),
                        TextEntry::make('country_code')->label('Country'),
                        TextEntry::make('cuisine_primary')->label('Cuisine')->placeholder('—'),
                        TextEntry::make('price_range')->placeholder('—'),
                        TextEntry::make('phone')->placeholder('—'),
                        TextEntry::make('website')->placeholder('—'),
                        TextEntry::make('google_place_id')->label('Google Place ID')->placeholder('—')->copyable(),
                        TextEntry::make('google_rating')
                            ->label('Google rating')
                            ->formatStateUsing(fn (Place $record): string => "★{$record->google_rating} ({$record->google_rating_count})")
                            ->placeholder('—'),
                        TextEntry::make('shares_count')->label('Sources'),
                        TextEntry::make('avg_extraction_confidence')->label('Avg extraction confidence')->placeholder('—'),
                        TextEntry::make('created_at')->dateTime(),
                    ]),
                Section::make('Location')
                    ->schema([
                        ViewEntry::make('map')
                            ->view('filament.places.map')
                            ->viewData(fn (Place $record): array => ['coordinates' => $record->coordinates()]),
                    ])
                    ->collapsible(),
                Section::make('Possible duplicates')
                    ->description('Nearby places scored by the same query the pipeline dedup uses (trigram/Jaro-Winkler similarity within 75 m).')
                    ->schema([
                        ViewEntry::make('candidates')
                            ->view('filament.places.candidates')
                            ->viewData(fn (Place $record): array => [
                                'candidates' => app(PlaceResolver::class)->candidatesFor($record),
                            ]),
                    ])
                    ->visible(fn (Place $record): bool => in_array($record->status, [PlaceStatus::Pending, PlaceStatus::Active], true)),
            ]);
    }
}
