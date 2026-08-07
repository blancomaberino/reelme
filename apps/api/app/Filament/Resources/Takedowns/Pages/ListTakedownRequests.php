<?php

namespace App\Filament\Resources\Takedowns\Pages;

use App\Filament\Resources\Takedowns\TakedownRequestResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTakedownRequests extends ListRecords
{
    protected static string $resource = TakedownRequestResource::class;

    /**
     * Notices arrive by email, so logging one is a CREATE here — the only
     * Filament resource in Moderation where an admin authors the record rather
     * than triaging one the app produced.
     *
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Log a notice'),
        ];
    }
}
