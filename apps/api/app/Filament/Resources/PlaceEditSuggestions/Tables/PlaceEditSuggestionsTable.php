<?php

namespace App\Filament\Resources\PlaceEditSuggestions\Tables;

use App\Enums\SuggestionStatus;
use App\Models\PlaceEditSuggestion;
use App\Models\User;
use App\Services\Places\PlaceSuggestionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The suggested-edit queue (T-083).
 *
 * The diff IS the row. A moderator's whole job here is "is this correction
 * right", and that question is unanswerable from a field name and a timestamp —
 * so `from → to` is rendered in the table itself and again in the approve
 * modal, rather than behind a view page nobody would open for the fast ones.
 */
class PlaceEditSuggestionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('place.name')
                    ->label('Place')
                    ->searchable()
                    // City + country, as in the claims queue: two venues share a
                    // name often enough that the reviewer has to know which.
                    ->description(fn (PlaceEditSuggestion $record): ?string => collect([
                        $record->place?->city,
                        $record->place?->country_code,
                    ])->filter()->implode(', ') ?: null),
                TextColumn::make('user.username')
                    ->label('Submitted by')
                    // A purged submitter (T-050) leaves the proposal and drops
                    // the name — the row is still the venue's to act on.
                    ->placeholder('(deleted)')
                    ->searchable(),
                // What KIND of row this is, before the reviewer reads a word of
                // it. A note-only row needs a different verb (Actioned) and a
                // different kind of attention — going and looking at the place —
                // so it must not be scannable only by noticing an empty diff.
                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    // "Note only" rather than "Note": the column beside it is
                    // LABELLED Note, and a badge sharing that word makes any
                    // assertion about the badge satisfiable by the header.
                    ->state(fn (PlaceEditSuggestion $record): string => match (true) {
                        $record->isNoteOnly() => 'Note only',
                        $record->hasNote() => 'Edit + note',
                        default => 'Field edit',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Note only' => 'info',
                        'Edit + note' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('changes')
                    ->label('Proposed change')
                    // `->state()` rather than `formatStateUsing`: a column whose
                    // state is blank short-circuits to the placeholder and never
                    // calls the formatter, so a `{}` row would render as an
                    // em-dash with no hint that it is malformed rather than empty.
                    ->state(fn (PlaceEditSuggestion $record): ?string => self::describe($record))
                    ->placeholder('(nothing)')
                    ->wrap(),
                // The note IS the finding on a note-only row, so it is rendered
                // in the table rather than behind a view page — same reasoning
                // as the diff beside it. Bounded here because 2000 characters
                // would push every other row off the screen; the full text is on
                // the tooltip and in the decision modals.
                TextColumn::make('note')
                    ->label('Note')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(180)
                    // A wrapped column with no floor gets whatever the other ten
                    // leave it — measured at 69px on the real queue, which is
                    // one word per line and a row twenty lines tall. A
                    // PERCENTAGE does not fix it: the table is `table-layout:
                    // auto`, where a percentage is a suggestion the browser
                    // drops as soon as the other columns' min-content fills the
                    // row (verified — `width: 30%` rendered at those same 69px).
                    // A min-width is a floor the auto algorithm has to respect.
                    ->extraHeaderAttributes(['style' => 'min-width: 20rem'])
                    ->extraAttributes(['style' => 'min-width: 20rem'])
                    ->tooltip(fn (PlaceEditSuggestion $record): ?string => $record->note),
                IconColumn::make('is_owner_submission')
                    ->label('Operator')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (SuggestionStatus $state): string => match ($state) {
                        SuggestionStatus::Approved => 'success',
                        SuggestionStatus::Pending => 'warning',
                        SuggestionStatus::Rejected => 'danger',
                        // Settled, but nothing was applied from the row itself —
                        // not the same fact as an approval, and it must not read
                        // like one.
                        SuggestionStatus::Actioned => 'info',
                    }),
                // Doubles as "what was done about it" on an actioned row — one
                // column, because both are the reviewer's written record of how
                // the row was settled.
                //
                // Both of these are hidden by default now: they are empty on
                // every row of the PENDING queue, which is the view this page
                // opens on, and the space they were holding is what a note needs
                // to be readable. Still one click away for the settled views,
                // where they are the interesting columns.
                TextColumn::make('reason')
                    ->label('Reviewer note')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reviewedBy.username')
                    ->label('Reviewed by')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            // Oldest first: this is a work queue, and a correction that has been
            // waiting a week is more urgent than one filed this morning.
            ->defaultSort('created_at', 'asc')
            ->filters([
                SelectFilter::make('status')
                    ->options(SuggestionStatus::class)
                    ->default(SuggestionStatus::Pending->value),
                TernaryFilter::make('is_owner_submission')->label('Operator edits'),
                // Notes are the rows that need a person to go and look at
                // something, so they are worth being able to work as a batch.
                TernaryFilter::make('note')
                    ->label('With a note')
                    ->nullable()
                    ->trueLabel('With a note')
                    ->falseLabel('Field edits only')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('note'),
                        false: fn (Builder $query): Builder => $query->whereNull('note'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    // The diff again, at the moment of deciding: the table row
                    // may have scrolled out of view behind the modal.
                    ->modalDescription(fn (PlaceEditSuggestion $record): string => 'Apply this change to the place: '
                        .(self::describe($record) ?? 'nothing')
                        .'. Every field it changes becomes human-locked, so enrichment will not overwrite it.'
                        // A row can carry both. Approving applies the fields and
                        // settles the row — which would quietly close the note
                        // too, so the note is put in front of the reviewer at the
                        // moment they decide, not just in the table behind it.
                        .($record->hasNote() ? "\n\nThey also wrote: \"{$record->note}\" — deal with that before settling this row." : ''))
                    // Hidden on a note-only row: there is nothing to apply, so
                    // "approved" would be a claim about an edit that never
                    // happened. The service refuses it too — this only keeps the
                    // button off a screen where it would be the wrong answer.
                    ->visible(fn (PlaceEditSuggestion $record): bool => $record->isPending() && ! $record->isNoteOnly())
                    ->action(function (PlaceEditSuggestion $record): void {
                        $applied = app(PlaceSuggestionService::class)->approve($record, self::admin());

                        // Said out loud, because "approved but changed nothing"
                        // looks identical to a successful apply in the table:
                        // the row goes green either way.
                        if ($applied->place_edit_id === null) {
                            Notification::make()
                                ->warning()
                                ->title('Approved — nothing to change')
                                ->body('The place already holds these values, so no edit was recorded.')
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('Suggestion applied')->send();
                    }),
                // The verb a note needs (T-112). `approve` means "apply the
                // patch", and "this place closed down" has none — what settles
                // it is a person doing something, and the only record of that is
                // what they write here.
                Action::make('actioned')
                    ->label('Actioned')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Mark as actioned')
                    ->modalDescription(fn (PlaceEditSuggestion $record): string => 'They wrote: "'
                        .(string) $record->note
                        .'". Do whatever it calls for on the place itself, then record it here. This settles the row; it does not change the place.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('What did you do?')
                            ->required()
                            ->minLength(3)
                            ->maxLength(500)
                            ->helperText('The only record of how this was resolved — "hid the place, confirmed closed by phone".'),
                    ])
                    // Note-only rows exclusively. A row proposing a field change
                    // has a real patch to apply or refuse, and Actioned must not
                    // become the one click that makes an awkward row go away
                    // without anything reaching the place.
                    ->visible(fn (PlaceEditSuggestion $record): bool => $record->isPending() && $record->isNoteOnly())
                    ->action(function (PlaceEditSuggestion $record, array $data): void {
                        app(PlaceSuggestionService::class)->action($record, self::admin(), $data['reason']);
                        Notification::make()->success()->title('Suggestion actioned')->send();
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (PlaceEditSuggestion $record): string => 'Decline this suggestion. The place is not changed.'
                        // Rejecting a note-only row is the abuse path (nonsense,
                        // an insult, a duplicate), so the words being refused
                        // have to be readable at the moment of refusing them.
                        .($record->hasNote() ? "\n\nThey wrote: \"{$record->note}\"" : ''))
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->required()
                            ->minLength(3)
                            ->maxLength(500)
                            ->helperText('Recorded on the suggestion. The only record of why a correction was refused.'),
                    ])
                    ->visible(fn (PlaceEditSuggestion $record): bool => $record->isPending())
                    ->action(function (PlaceEditSuggestion $record, array $data): void {
                        app(PlaceSuggestionService::class)->reject($record, self::admin(), $data['reason']);
                        Notification::make()->success()->title('Suggestion rejected')->send();
                    }),
            ]);
    }

    /**
     * One line per field: `phone: — → +598 2 900 0000`. Null when the row
     * carries no readable change, so the column shows its placeholder instead of
     * an empty cell.
     */
    private static function describe(PlaceEditSuggestion $record): ?string
    {
        $lines = [];

        foreach ($record->changes as $field => $change) {
            if (! is_array($change)) {
                continue;
            }

            $lines[] = sprintf(
                '%s: %s → %s',
                $field,
                self::value($change['from'] ?? null),
                self::value($change['to'] ?? null),
            );
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /** A proposed value as one readable string — including the array ones. */
    private static function value(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_array($value)) {
            // Opening hours: a list of rule lines, joined so the diff stays one
            // line per FIELD rather than one per rule.
            return implode(' · ', array_map(fn ($line): string => is_scalar($line) ? (string) $line : '?', $value));
        }

        return is_scalar($value) ? (string) $value : '?';
    }

    private static function admin(): User
    {
        /** @var User $admin */
        $admin = Auth::user();

        return $admin;
    }
}
