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
                TextColumn::make('changes')
                    ->label('Proposed change')
                    // `->state()` rather than `formatStateUsing`: a column whose
                    // state is blank short-circuits to the placeholder and never
                    // calls the formatter, so a `{}` row would render as an
                    // em-dash with no hint that it is malformed rather than empty.
                    ->state(fn (PlaceEditSuggestion $record): ?string => self::describe($record))
                    ->placeholder('(nothing)')
                    ->wrap(),
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
                    }),
                TextColumn::make('reason')->placeholder('—')->toggleable(),
                TextColumn::make('reviewedBy.username')
                    ->label('Reviewed by')
                    ->placeholder('—')
                    ->toggleable(),
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
                        .'. Every field it changes becomes human-locked, so enrichment will not overwrite it.')
                    ->visible(fn (PlaceEditSuggestion $record): bool => $record->isPending())
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
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Decline this suggestion. The place is not changed.')
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
