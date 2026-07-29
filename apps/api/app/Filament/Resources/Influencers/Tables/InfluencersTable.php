<?php

namespace App\Filament\Resources\Influencers\Tables;

use App\Enums\Platform;
use App\Models\Influencer;
use App\Models\User;
use App\Services\Influencers\InfluencerClaimService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class InfluencersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('platform')
                    ->badge(),
                TextColumn::make('handle')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('display_name')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('follower_count_cached')
                    ->label('Followers')
                    ->numeric()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('claimedBy.username')
                    ->label('Claimed by')
                    ->badge()
                    ->color('success')
                    ->placeholder('— unclaimed'),
                TextColumn::make('pending_claims_count')
                    ->label('Pending claims')
                    ->badge()
                    ->color('warning')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('platform')
                    ->options(Platform::class),
                TernaryFilter::make('claimed')
                    ->label('Claim status')
                    ->placeholder('All')
                    ->trueLabel('Claimed')
                    ->falseLabel('Unclaimed')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNotNull('claimed_by_user_id'),
                        false: fn (Builder $q): Builder => $q->whereNull('claimed_by_user_id'),
                        blank: fn (Builder $q): Builder => $q,
                    ),
            ])
            ->recordActions([
                self::assignAction(),
                self::releaseAction(),
            ]);
    }

    /**
     * Manually claim (or reassign) this identity for a chosen user — no OAuth/bio
     * proof, the interim tool until Instagram OAuth is live.
     */
    private static function assignAction(): Action
    {
        return Action::make('assign')
            ->label(fn (Influencer $record): string => $record->claimed_by_user_id !== null ? 'Reassign' : 'Assign to user')
            ->icon('heroicon-o-user-plus')
            ->modalHeading(fn (Influencer $record): string => "Assign @{$record->handle} to a user")
            ->modalDescription('Manually claims this identity for the chosen user (same effect as a verified claim: sets their influencer flag, fires the claim event). If it is already claimed by someone else, this reassigns it and demotes the previous owner.')
            ->modalSubmitActionLabel('Assign')
            ->schema([
                Select::make('user_id')
                    ->label('Reelmap user')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => User::query()
                        ->where('username', 'ilike', '%'.$search.'%')
                        ->orWhere('email', 'ilike', '%'.$search.'%')
                        ->orderBy('username')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (User $u): array => [$u->id => "{$u->username} ({$u->email})"])
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => User::find($value)?->username),
            ])
            ->action(function (Influencer $record, array $data): void {
                $user = User::findOrFail($data['user_id']);
                app(InfluencerClaimService::class)->assignByAdmin($record, $user, self::admin());

                Notification::make()
                    ->success()
                    ->title("Assigned @{$record->handle} to {$user->username}")
                    ->send();
            });
    }

    /** Unclaim this identity entirely (no new owner). */
    private static function releaseAction(): Action
    {
        return Action::make('release')
            ->label('Release claim')
            ->icon('heroicon-o-lock-open')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Unclaims this identity: the current owner loses the link (and their influencer flag if they hold no other identity). Their claim is closed.')
            ->visible(fn (Influencer $record): bool => $record->claimed_by_user_id !== null)
            ->action(function (Influencer $record): void {
                app(InfluencerClaimService::class)->releaseByAdmin($record, self::admin());

                Notification::make()
                    ->success()
                    ->title("Released @{$record->handle}")
                    ->send();
            });
    }

    private static function admin(): User
    {
        /** @var User $admin */
        $admin = Auth::user();

        return $admin;
    }
}
