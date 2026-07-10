<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        // Deliberately no Delete/ForceDelete here: user removal goes through the
        // table `ban` action, which revokes Sanctum tokens and guards self-ban and
        // keeps the username/email reserved. A raw delete would skip all of that.
        // Restore stays as the edit-page convenience alias for `unban`.
        return [
            RestoreAction::make(),
        ];
    }

    /**
     * Role flags are guarded (not mass-assignable) so the API can't set them;
     * the admin panel is the deliberate exception, so forceFill here.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->forceFill($data)->save();

        return $record;
    }
}
