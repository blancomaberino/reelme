<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Role flags are guarded (not mass-assignable); the admin panel is the
     * deliberate exception, so forceFill here.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = new (static::getModel());
        $user->forceFill($data)->save();

        return $user;
    }
}
