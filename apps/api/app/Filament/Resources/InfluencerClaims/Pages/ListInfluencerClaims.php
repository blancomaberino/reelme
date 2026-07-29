<?php

namespace App\Filament\Resources\InfluencerClaims\Pages;

use App\Filament\Resources\InfluencerClaims\InfluencerClaimResource;
use App\Models\Influencer;
use App\Models\User;
use App\Services\Influencers\InfluencerClaimService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListInfluencerClaims extends ListRecords
{
    protected static string $resource = InfluencerClaimResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->assignAction(),
        ];
    }

    /**
     * Manual claim: bind an auto-created influencer identity to a Reelmap user
     * without OAuth/bio proof — the interim tool until Instagram OAuth is live.
     */
    private function assignAction(): Action
    {
        return Action::make('assign')
            ->label('Assign to a user')
            ->icon('heroicon-o-user-plus')
            ->modalHeading('Assign an influencer identity to a user')
            ->modalDescription('Manually claims this identity for the chosen user (same effect as a verified claim: sets their influencer flag, fires the claim event). If it is already claimed by someone else, this reassigns it and demotes the previous owner.')
            ->modalSubmitActionLabel('Assign')
            ->schema([
                Select::make('influencer_id')
                    ->label('Influencer identity')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Influencer::query()
                        ->where('handle', 'ilike', '%'.$search.'%')
                        ->orderBy('handle')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Influencer $i): array => [
                            $i->id => "@{$i->handle} ({$i->platform->value})".($i->claimed_by_user_id !== null ? ' — already claimed' : ''),
                        ])
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Influencer::find($value)?->handle),
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
            ->action(function (array $data): void {
                $influencer = Influencer::findOrFail($data['influencer_id']);
                $user = User::findOrFail($data['user_id']);

                /** @var User $admin */
                $admin = Auth::user();
                app(InfluencerClaimService::class)->assignByAdmin($influencer, $user, $admin);

                Notification::make()
                    ->success()
                    ->title("Assigned @{$influencer->handle} to {$user->username}")
                    ->send();
            });
    }
}
