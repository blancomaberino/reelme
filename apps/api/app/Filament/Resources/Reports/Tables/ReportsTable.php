<?php

namespace App\Filament\Resources\Reports\Tables;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Offer;
use App\Models\Place;
use App\Models\Report;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use App\Services\Moderation\ReportActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The triage table (T-049).
 *
 * Sorted so the queue answers the only question that matters on arrival: what
 * should I look at first. Urgent reasons (copyright, fraud, inappropriate)
 * carry legal, financial and store-review consequences that grow by the hour;
 * `wrong_place` is a correctness bug that can wait.
 *
 * Every destructive action requires a written note. Not ceremony: a takedown
 * dispute, a DMCA counter-notice and an app-store appeal all ask the same
 * question — why did you remove this — and an audit trail of bare timestamps
 * cannot answer it.
 */
class ReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reason')
                    ->badge()
                    ->color(fn (ReportReason $state): string => $state->isUrgent() ? 'danger' : 'gray')
                    ->sortable(),
                TextColumn::make('reportable_type')
                    ->label('Target')
                    ->badge()
                    ->color('gray'),
                // The reportable is polymorphic, so there is no single column to
                // show. A one-line description per type is what lets an admin
                // triage without opening every row.
                TextColumn::make('reportable_id')
                    ->label('What')
                    ->formatStateUsing(fn (Report $record): string => self::describe($record))
                    ->wrap()
                    ->limit(90),
                TextColumn::make('details')
                    ->label('Said')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('reporter.username')
                    ->label('By')
                    ->searchable(),
                // One report is a complaint; six against the same target is a
                // pattern. Without this an admin decides on a fraction of the
                // evidence — and it is the number that separates a brigading
                // campaign from a real problem.
                // Selected as a correlated subquery in getEloquentQuery(), not
                // computed per row: a ->state() closure here issued one COUNT
                // per row, so a 50-row queue cost 50 extra queries on the page
                // whose whole purpose is being scanned quickly.
                TextColumn::make('same_target_count')
                    ->label('Total')
                    ->badge()
                    ->color(fn ($state): string => (int) $state > 2 ? 'danger' : 'gray'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ReportStatus $state): string => match ($state) {
                        ReportStatus::Open => 'warning',
                        ReportStatus::Reviewing => 'info',
                        ReportStatus::Resolved => 'success',
                        ReportStatus::Dismissed => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('resolver.username')
                    ->label('Decided by')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            // Oldest first WITHIN the queue: the metric a store reviewer asks
            // about is time-to-response, and newest-first quietly starves the
            // reports that have been waiting longest.
            ->defaultSort('created_at', 'asc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ReportStatus::cases())->pluck('value', 'value')->all())
                    ->default(ReportStatus::Open->value),
                SelectFilter::make('reason')
                    ->options(collect(ReportReason::cases())->pluck('value', 'value')->all()),
                SelectFilter::make('reportable_type')
                    ->label('Target type')
                    ->options(fn (): array => Report::query()
                        ->distinct()
                        ->pluck('reportable_type', 'reportable_type')
                        ->all()),
            ])
            ->recordActions([
                self::takeDownAction(),
                self::banAction(),
                self::dismissAction(),
            ]);
    }

    /**
     * Hide the reported content. Routes to the T-072 moderators — this is not a
     * second take-down implementation, and must never become one.
     */
    private static function takeDownAction(): Action
    {
        return Action::make('take_down')
            ->label('Take down')
            ->icon('heroicon-o-eye-slash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Hides the reported content from every public surface. Reversible from the Places/Shares resource.')
            ->schema(self::decisionForm())
            ->visible(fn (Report $record): bool => $record->status->isOpen())
            ->action(function (Report $record, array $data): void {
                $acted = app(ReportActions::class)->takeDown($record, self::admin(), $data['note']);

                if ($data['sweep'] ?? false) {
                    // `fresh()` because the sweep copies the PRIMARY's status
                    // onto the siblings, and the in-memory record still shows
                    // the pre-resolve one.
                    app(ReportActions::class)->resolveSiblings($record->fresh(), self::admin(), $data['note']);
                }

                if (! $acted) {
                    // Said plainly rather than reported as a success: "nothing
                    // to take down" and "taken down" must not look identical to
                    // whoever is working the queue.
                    Notification::make()
                        ->warning()
                        ->title('Nothing to take down')
                        ->body('The reported content is already gone, or its type has no take-down (a source post is shared between users; an offer is the venue’s record).')
                        ->send();
                }
            });
    }

    /** Ban the reported ACCOUNT — only meaningful when the target is a user. */
    private static function banAction(): Action
    {
        return Action::make('ban')
            ->label('Ban user')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Revokes every API token and hides the account. Their username and email stay reserved, and their financial history is untouched.')
            ->schema(self::decisionForm())
            ->visible(fn (Report $record): bool => $record->status->isOpen()
                && $record->reportable instanceof User
                && ! $record->reportable->trashed())
            ->action(function (Report $record, array $data): void {
                $banned = app(ReportActions::class)->banReported($record, self::admin(), $data['note']);

                if (! $banned) {
                    // Said out loud, like the take-down path. Silently doing
                    // nothing is how an admin believes a ban landed.
                    Notification::make()
                        ->warning()
                        ->title('Nothing to ban')
                        ->body('That account is already banned, or it is your own.')
                        ->send();

                    return;
                }

                if ($data['sweep'] ?? false) {
                    app(ReportActions::class)->resolveSiblings($record->fresh(), self::admin(), $data['note']);
                }
            });
    }

    private static function dismissAction(): Action
    {
        return Action::make('dismiss')
            ->icon('heroicon-o-check')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Closes the report with no action. The reporter is not notified.')
            ->schema(self::decisionForm())
            ->visible(fn (Report $record): bool => $record->status->isOpen())
            ->action(function (Report $record, array $data): void {
                app(ReportActions::class)->dismiss($record, self::admin(), $data['note']);

                if ($data['sweep'] ?? false) {
                    app(ReportActions::class)->resolveSiblings($record->fresh(), self::admin(), $data['note']);
                }
            });
    }

    /**
     * The note is REQUIRED on every action — see the class docblock.
     *
     * @return list<Component>
     */
    private static function decisionForm(): array
    {
        return [
            Textarea::make('note')
                ->label('Why')
                ->required()
                ->minLength(3)
                ->maxLength(500)
                ->helperText('Recorded in the audit log. This is what a takedown dispute or a store appeal is answered with.'),
            Toggle::make('sweep')
                ->label('Also close other reports about this same target')
                ->default(true)
                ->helperText('Six reports about one share are one decision.'),
        ];
    }

    /** A one-line description of whatever was reported. */
    private static function describe(Report $record): string
    {
        $target = $record->reportable;

        if ($target === null) {
            return "#{$record->reportable_id} (deleted)";
        }

        return match (true) {
            $target instanceof Place => $target->name,
            $target instanceof Share => 'share #'.$target->id.' by @'.($target->user->username ?? '?'),
            $target instanceof User => '@'.$target->username,
            $target instanceof SourcePost => $target->url,
            $target instanceof Offer => $target->title,
            default => "#{$record->reportable_id}",
        };
    }

    private static function admin(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /** @param  Builder<Report>  $query */
    public static function openOnly(Builder $query): void
    {
        $query->open();
    }
}
