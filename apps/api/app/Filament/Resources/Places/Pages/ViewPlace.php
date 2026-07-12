<?php

namespace App\Filament\Resources\Places\Pages;

use App\Enums\PlaceStatus;
use App\Filament\Resources\Places\PlaceResource;
use App\Models\Place;
use App\Models\PlaceMerge;
use App\Services\Places\PlaceMerger;
use App\Services\Places\PlaceResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;
use Throwable;

/**
 * The dedup decision surface (T-035): approve a pending place as genuinely
 * new, merge it into a nearby candidate duplicate, hide spam, or undo a
 * wrong merge. Actions delegate to {@see PlaceMerger}; the candidate list
 * shares {@see PlaceResolver::candidatesFor()} with the pipeline dedup.
 */
class ViewPlace extends ViewRecord
{
    protected static string $resource = PlaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->approveAction(),
            $this->mergeAction(),
            $this->hideAction(),
            $this->restoreAction(),
            $this->unmergeAction(),
        ];
    }

    /** Pending → active: "this is a real, distinct restaurant". */
    private function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve as new')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Place $record): bool => $record->status === PlaceStatus::Pending)
            ->action(function (Place $record): void {
                $record->status = PlaceStatus::Active;
                $record->save(); // model save → Scout picks it up

                Notification::make()->title('Place approved')->success()->send();
            });
    }

    private function mergeAction(): Action
    {
        return Action::make('merge')
            ->label('Merge into…')
            ->icon('heroicon-o-arrows-pointing-in')
            ->color('warning')
            ->visible(fn (Place $record): bool => in_array($record->status, [PlaceStatus::Pending, PlaceStatus::Active], true))
            ->schema(fn (Place $record): array => [
                Select::make('target_place_id')
                    ->label('Merge this place into')
                    ->helperText('This place becomes a tombstone; its sources, tags and counters move to the target. Undo is available from the merged place.')
                    ->options(self::candidateOptions($record))
                    ->default(fn (): ?int => array_key_first(self::candidateOptions($record)))
                    ->required()
                    ->rule('integer'),
            ])
            ->requiresConfirmation()
            ->modalDescription('The selected candidate survives; this place is folded into it.')
            ->action(function (Place $record, array $data): void {
                $target = Place::query()->findOrFail((int) $data['target_place_id']);

                // Re-validate against the candidate set server-side: the picker is
                // UI, not authority — a tampered id must not merge arbitrary places.
                $candidateIds = array_map(
                    fn (array $c) => $c['place_id'],
                    app(PlaceResolver::class)->candidatesFor($record),
                );
                if (! in_array($target->id, $candidateIds, true)) {
                    Notification::make()->title('Not a candidate')->body('The target must come from the candidate list.')->danger()->send();

                    return;
                }

                $survivor = app(PlaceMerger::class)->merge($target, $record, auth()->user());

                Notification::make()->title("Merged into “{$survivor->name}”")->success()->send();

                $this->redirect(PlaceResource::getUrl('view', ['record' => $survivor]));
            });
    }

    private function hideAction(): Action
    {
        return Action::make('hide')
            ->icon('heroicon-o-eye-slash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('The place disappears from the map, browse and search. Its sources are kept.')
            ->visible(fn (Place $record): bool => in_array($record->status, [PlaceStatus::Pending, PlaceStatus::Active], true))
            ->action(function (Place $record): void {
                $record->status = PlaceStatus::Hidden;
                $record->save(); // shouldBeSearchable() now false → de-indexed

                Notification::make()->title('Place hidden')->success()->send();
            });
    }

    /** Hidden → pending: a moderation mistake, back into the queue. */
    private function restoreAction(): Action
    {
        return Action::make('restore')
            ->label('Restore to queue')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (Place $record): bool => $record->status === PlaceStatus::Hidden)
            ->action(function (Place $record): void {
                $record->status = PlaceStatus::Pending;
                $record->save();

                Notification::make()->title('Place restored to the review queue')->success()->send();
            });
    }

    private function unmergeAction(): Action
    {
        return Action::make('unmerge')
            ->icon('heroicon-o-arrows-pointing-out')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Restores this place (sources, tags, counters) and rolls the survivor back to its pre-merge state.')
            ->visible(fn (Place $record): bool => $record->status === PlaceStatus::Merged
                && self::undoableMerge($record) !== null)
            ->action(function (Place $record): void {
                $merge = self::undoableMerge($record);
                if ($merge === null) {
                    Notification::make()->title('Nothing to undo')->danger()->send();

                    return;
                }

                try {
                    $restored = app(PlaceMerger::class)->unmerge($merge);
                } catch (RuntimeException $e) {
                    Notification::make()->title('Cannot undo this merge')->body($e->getMessage())->danger()->send();

                    return;
                } catch (Throwable $e) {
                    // e.g. a constraint collision from out-of-band edits — the
                    // transaction rolled back; surface it instead of a 500.
                    report($e);
                    Notification::make()->title('Cannot undo this merge')->body('The restore failed and was rolled back. Check the logs.')->danger()->send();

                    return;
                }

                Notification::make()->title("“{$restored->name}” restored")->success()->send();

                $this->redirect(PlaceResource::getUrl('view', ['record' => $restored]));
            });
    }

    private static function undoableMerge(Place $record): ?PlaceMerge
    {
        return PlaceMerge::query()
            ->where('source_place_id', $record->id)
            ->whereNull('undone_at')
            ->latest('id')
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private static function candidateOptions(Place $record): array
    {
        $options = [];
        foreach (app(PlaceResolver::class)->candidatesFor($record) as $candidate) {
            $options[(int) $candidate['place_id']] = sprintf(
                '%s — %.1f%% · %dm · %s',
                $candidate['name'],
                $candidate['similarity'] * 100,
                (int) round($candidate['distance_m']),
                $candidate['address'],
            );
        }

        return $options;
    }
}
