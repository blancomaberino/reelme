<?php

namespace App\Filament\Resources\Tags\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Canonical English source — read-only; localization never changes it.
                TextInput::make('name')
                    ->label('Name (English, canonical)')
                    ->disabled(),
                TextInput::make('kind')
                    ->disabled(),
                // The reviewable/overridable label. Dot notation writes into the
                // `name_i18n` JSON array cast.
                TextInput::make('name_i18n.es')
                    ->label('Spanish label')
                    ->maxLength(80)
                    ->helperText('Overrides the auto/dictionary translation. Leave blank to fall back to the English name.'),
            ]);
    }
}
