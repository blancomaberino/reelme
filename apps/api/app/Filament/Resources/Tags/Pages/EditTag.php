<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Resources\Tags\TagResource;
use Filament\Resources\Pages\EditRecord;

class EditTag extends EditRecord
{
    protected static string $resource = TagResource::class;

    /**
     * Drop blank locale overrides so a cleared field means "fall back to the
     * English name" (a stored "" would otherwise display as an empty label).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $i18n = array_filter((array) ($data['name_i18n'] ?? []), fn ($v) => filled($v));
        $data['name_i18n'] = $i18n === [] ? null : $i18n;

        return $data;
    }
}
