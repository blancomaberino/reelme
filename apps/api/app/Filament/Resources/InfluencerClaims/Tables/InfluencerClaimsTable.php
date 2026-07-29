<?php

namespace App\Filament\Resources\InfluencerClaims\Tables;

use App\Enums\ClaimStatus;
use App\Models\InfluencerClaim;
use App\Models\User;
use App\Services\Influencers\InfluencerClaimService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class InfluencerClaimsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('influencer.handle')
                    ->label('Influencer')
                    ->searchable(),
                TextColumn::make('influencer.platform')
                    ->label('Platform')
                    ->badge(),
                TextColumn::make('user.username')
                    ->label('Claimant')
                    ->searchable(),
                TextColumn::make('method')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ClaimStatus $state): string => match ($state) {
                        ClaimStatus::Verified => 'success',
                        ClaimStatus::Pending => 'warning',
                        ClaimStatus::Rejected => 'danger',
                    }),
                TextColumn::make('reason')
                    ->placeholder('—'),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(ClaimStatus::class),
                // Disputes (06 §5.1): identities with ≥2 live (pending or verified)
                // claims — two people asserting the same influencer.
                Filter::make('disputed')
                    ->label('Disputed only')
                    ->query(fn (Builder $query): Builder => $query->whereIn('influencer_id', function ($sub): void {
                        $sub->select('influencer_id')
                            ->from('influencer_claims')
                            ->whereIn('status', [ClaimStatus::Pending->value, ClaimStatus::Verified->value])
                            ->groupBy('influencer_id')
                            ->havingRaw('count(*) >= 2');
                    })),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Verify this claim. If the identity is already claimed by another account, this OVERRIDES it — the previous owner loses the link (and their influencer flag if they hold no other identity).')
                    ->visible(fn (InfluencerClaim $record): bool => $record->status === ClaimStatus::Pending)
                    ->action(function (InfluencerClaim $record): void {
                        app(InfluencerClaimService::class)->approve($record, self::admin());
                        Notification::make()->success()->title('Claim approved')->send();
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Reject this claim and notify the claimant. Their pending code is invalidated.')
                    ->visible(fn (InfluencerClaim $record): bool => $record->status === ClaimStatus::Pending)
                    ->action(function (InfluencerClaim $record): void {
                        app(InfluencerClaimService::class)->reject($record, self::admin());
                        Notification::make()->success()->title('Claim rejected')->send();
                    }),
            ]);
    }

    private static function admin(): User
    {
        /** @var User $admin */
        $admin = Auth::user();

        return $admin;
    }
}
