<?php

namespace App\Filament\Resources\Takedowns\Schemas;

use App\Enums\TakedownRequesterRole;
use App\Enums\TakedownStatus;
use App\Models\SourcePost;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TakedownRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('requester_name')->required()->maxLength(255),
            TextInput::make('requester_email')->email()->required()->maxLength(255),
            Select::make('requester_role')
                ->options(collect(TakedownRequesterRole::cases())->pluck('value', 'value')->all())
                ->default(TakedownRequesterRole::Rightsholder->value)
                ->required()
                ->helperText('A rightsholder notice carries a response clock; an influencer asking us to stop citing their own post does not.'),

            // The URL comes first because that is what the email contains. The
            // post is matched from it — logging must never be blocked on the
            // match, or notices sit in an inbox instead of the queue.
            TextInput::make('target_url')
                ->label('Post URL')
                ->url()
                ->maxLength(2048)
                ->helperText('As given in the notice. Match it to a post below when you can.'),
            Select::make('source_post_id')
                ->label('Matched post')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => SourcePost::query()
                    ->where('url', 'ilike', "%{$search}%")
                    ->limit(20)
                    ->pluck('url', 'id')
                    ->all())
                ->getOptionLabelUsing(fn ($value): ?string => SourcePost::find($value)?->url)
                ->helperText('Leave empty if you cannot find it — the notice is still logged and answerable.'),

            Textarea::make('notes')->rows(4)->maxLength(5000),
            Select::make('status')
                ->options(collect(TakedownStatus::cases())->pluck('value', 'value')->all())
                ->default(TakedownStatus::Received->value)
                ->required(),
        ]);
    }
}
