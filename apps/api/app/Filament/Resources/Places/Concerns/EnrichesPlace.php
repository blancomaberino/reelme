<?php

namespace App\Filament\Resources\Places\Concerns;

use App\Models\Place;
use App\Services\Places\Enrichment\BusinessEnricher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * The "Enrich as business" header action (T-084), shared by the view and edit
 * pages. Runs the {@see BusinessEnricher} — Google/GMB + the business website +
 * a review-cache refresh — independent of any share, respecting locked fields,
 * and reports what changed. The enricher never throws; a failing source degrades
 * to the others and is surfaced as a warning.
 */
trait EnrichesPlace
{
    protected function enrichAction(): Action
    {
        return Action::make('enrich')
            ->label('Enrich as business')
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Enrich this place from external sources')
            ->modalDescription('Pulls contact, hours, cuisine and a picture from Google, the business website, and the review sources. Fields you have edited by hand are kept.')
            ->action(function (Place $record): void {
                $result = app(BusinessEnricher::class)->enrich($record, auth()->id());
                $changed = $result->changedFields();

                $notification = Notification::make();
                if ($changed !== []) {
                    $notification->title('Place enriched')
                        ->body('Updated: '.implode(', ', $changed).'.')
                        ->success();
                } elseif ($result->anyFailed()) {
                    $notification->title('Enrichment finished with warnings')
                        ->body('No fields changed; one or more sources failed (see logs).')
                        ->warning();
                } else {
                    $notification->title('Nothing to update')
                        ->body('External sources had no new data, or every field is locked.')
                        ->info();
                }
                $notification->send();
                $this->afterEnrichment();
            });
    }

    /**
     * Hook after an enrich run — the edit page pulls the fresh values back into
     * the open form; the view page re-renders from the mutated record (no-op).
     */
    protected function afterEnrichment(): void {}
}
