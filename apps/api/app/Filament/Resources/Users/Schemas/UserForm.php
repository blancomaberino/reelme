<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(120),
                TextInput::make('username')
                    ->required()
                    ->maxLength(30),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                Textarea::make('bio')
                    ->columnSpanFull(),
                // Role flags — the point of the resource. Password, stripe columns
                // and model preference are intentionally NOT editable at M0.
                Toggle::make('is_admin'),
                Toggle::make('is_influencer'),
                Toggle::make('is_restaurant_owner'),
                Toggle::make('is_public'),
            ]);
    }
}
