<?php

namespace App\Filament\Resources\Places\Schemas;

use App\Filament\Resources\Places\Pages\EditPlace;
use App\Services\Places\PlaceEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The manual-edit form for a place's curated business fields (T-084). Places are
 * still created only by the pipeline (no create form); this edit surface lets a
 * human curate a place as a first-class business. Saving routes through
 * {@see PlaceEditor} (see {@see EditPlace}),
 * which locks every field the human changes and records an audit row — so a later
 * enrichment or re-share never clobbers a hand-set value.
 */
class PlaceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Business')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('cuisine_primary')->label('Cuisine')->maxLength(120),
                        Select::make('price_range')
                            ->label('Price range')
                            ->options([1 => '€', 2 => '€€', 3 => '€€€', 4 => '€€€€'])
                            ->native(false),
                        TextInput::make('phone')->tel()->maxLength(32),
                        TextInput::make('website')->url()->maxLength(2048)->columnSpanFull(),
                    ]),
                Section::make('Picture')
                    ->description('The map marker prefers the thumbnail, falling back to the main image; the place detail hero shows the main image. Paste a hosted image URL.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('image_url')->label('Main image URL')->url()->maxLength(2048),
                        TextInput::make('thumbnail_url')->label('Marker thumbnail URL')->url()->maxLength(2048),
                    ]),
                Section::make('Address')
                    ->columns(2)
                    ->schema([
                        TextInput::make('address_line1')->label('Address line 1')->maxLength(255),
                        TextInput::make('address_line2')->label('Address line 2')->maxLength(255),
                        TextInput::make('city')->maxLength(255),
                        TextInput::make('region')->maxLength(255),
                        TextInput::make('postal_code')->maxLength(32),
                        TextInput::make('country_code')->label('Country (ISO-2)')->maxLength(2),
                    ]),
                Section::make('Opening hours')
                    ->schema([
                        Textarea::make('opening_hours_json')
                            ->label('Opening hours')
                            ->helperText('One rule per line, e.g. “Mo-Fr 09:00–17:00”.')
                            ->rows(4)
                            ->formatStateUsing(fn ($state): string => is_array($state)
                                ? implode("\n", array_filter($state, 'is_string'))
                                : '')
                            ->dehydrateStateUsing(fn ($state): ?array => self::linesToArray($state)),
                    ]),
            ]);
    }

    /**
     * Split a textarea value into a trimmed, non-empty list of rule lines, or
     * null when blank (so clearing the field stores NULL, not an empty array).
     *
     * @return list<string>|null
     */
    private static function linesToArray(mixed $state): ?array
    {
        if (! is_string($state)) {
            return null;
        }

        $lines = array_values(array_filter(array_map(
            'trim',
            preg_split('/\r\n|\r|\n/', $state) ?: [],
        ), fn (string $line): bool => $line !== ''));

        return $lines === [] ? null : $lines;
    }
}
