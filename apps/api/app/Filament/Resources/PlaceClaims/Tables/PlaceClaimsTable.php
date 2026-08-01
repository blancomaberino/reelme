<?php

namespace App\Filament\Resources\PlaceClaims\Tables;

use App\Enums\ClaimStatus;
use App\Enums\PlaceClaimMethod;
use App\Models\PlaceClaim;
use App\Models\User;
use App\Services\Places\PlaceClaimService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class PlaceClaimsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('place.name')
                    ->label('Place')
                    ->searchable()
                    // City + country under the name: two venues can share a name,
                    // and the reviewer needs to know which one they are granting.
                    ->description(fn (PlaceClaim $record): ?string => collect([
                        $record->place?->city,
                        $record->place?->country_code,
                    ])->filter()->implode(', ') ?: null),
                TextColumn::make('user.username')
                    ->label('Claimant')
                    ->searchable(),
                TextColumn::make('method')
                    ->badge()
                    // Document is the one that needs a person; the automatic
                    // methods appearing here mean they failed or were disputed.
                    ->color(fn (PlaceClaimMethod $state): string => $state->isAutomatic() ? 'gray' : 'info'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ClaimStatus $state): string => match ($state) {
                        ClaimStatus::Verified => 'success',
                        ClaimStatus::Pending => 'warning',
                        ClaimStatus::Rejected => 'danger',
                    }),
                TextColumn::make('reason')->placeholder('—'),
                TextColumn::make('reviewedBy.username')
                    ->label('Reviewed by')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('verified_at')->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            // Oldest pending first: this is a queue with an SLA (06 §2.1 says two
            // business days), so the default sort is the work order, not newest.
            ->defaultSort('created_at', 'asc')
            ->filters([
                SelectFilter::make('status')
                    ->options(ClaimStatus::class)
                    ->default(ClaimStatus::Pending->value),
                SelectFilter::make('method')->options(PlaceClaimMethod::class),
                // Two or more people asserting the same venue. The automatic
                // methods can't resolve this — whoever verifies first wins the
                // index — so a human decides.
                Filter::make('disputed')
                    ->label('Disputed only')
                    ->query(fn (Builder $query): Builder => $query->whereIn('place_id', function ($sub): void {
                        $sub->select('place_id')
                            ->from('place_claims')
                            ->whereIn('status', [ClaimStatus::Pending->value, ClaimStatus::Verified->value])
                            ->groupBy('place_id')
                            ->havingRaw('count(*) >= 2');
                    })),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(
                        'Verify this claim. The claimant becomes the operator of this place: they can create offers, '
                        .'and fees for redemptions will be drawn against the venue. Any competing pending claim is closed.'
                    )
                    ->visible(fn (PlaceClaim $record): bool => $record->isPending())
                    ->action(function (PlaceClaim $record): void {
                        app(PlaceClaimService::class)->approve($record, self::admin());
                        Notification::make()->success()->title('Claim approved')->send();
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Reject this claim. The claimant is not granted operator access and can submit new evidence.')
                    ->visible(fn (PlaceClaim $record): bool => $record->isPending())
                    ->action(function (PlaceClaim $record): void {
                        app(PlaceClaimService::class)->reject($record, self::admin());
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
