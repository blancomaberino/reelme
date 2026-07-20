<?php

namespace App\Filament\Resources\Places\Pages;

use App\Filament\Resources\Places\Concerns\EnrichesPlace;
use App\Filament\Resources\Places\PlaceResource;
use App\Models\Place;
use App\Models\PlaceEdit;
use App\Services\Places\PlaceEditor;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Manual curation of a place's business fields (T-084). Every save routes through
 * the single {@see PlaceEditor} write path — origin `manual` — so the human edit
 * locks each field it changes (a later enrichment/re-share won't clobber it) and
 * lands one audit row. Places are pipeline-created, so there is no create page.
 */
class EditPlace extends EditRecord
{
    use EnrichesPlace;

    protected static string $resource = PlaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->enrichAction(),
        ];
    }

    /**
     * Persist through {@see PlaceEditor} instead of a bare update: it computes the
     * real diff, locks changed fields, and writes the audit trail.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Place $record */
        app(PlaceEditor::class)->apply(
            $record,
            $data,
            PlaceEdit::ORIGIN_MANUAL,
            auth()->id(),
        );

        return $record->refresh();
    }

    /** Pull the freshly-enriched curated values back into the open form. */
    protected function afterEnrichment(): void
    {
        $this->refreshFormData(Place::CURATED_FIELDS);
    }
}
