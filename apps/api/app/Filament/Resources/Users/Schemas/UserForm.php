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
                    ->maxLength(30)
                    ->unique('users', 'username', ignoreRecord: true),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->unique('users', 'email', ignoreRecord: true),
                // Set on create so panel-created accounts can actually authenticate
                // (the model's `hashed` cast hashes it). Hidden on edit — password
                // management stays out of the role-admin panel at M0.
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->maxLength(255)
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->visibleOn('create'),
                Textarea::make('bio')
                    ->columnSpanFull(),
                // Role flags — the point of the resource. Stripe columns and model
                // preference are intentionally NOT editable at M0.
                Toggle::make('is_admin'),
                Toggle::make('is_influencer'),
                Toggle::make('is_restaurant_owner'),
                Toggle::make('is_public')
                    ->default(true), // match the users.is_public DB default
            ]);
    }
}
