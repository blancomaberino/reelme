<?php

namespace Database\Factories;

use App\Enums\SuggestionStatus;
use App\Models\Place;
use App\Models\PlaceEditSuggestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaceEditSuggestion>
 */
class PlaceEditSuggestionFactory extends Factory
{
    protected $model = PlaceEditSuggestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'place_id' => Place::factory(),
            'user_id' => User::factory(),
            'changes' => [
                'phone' => ['from' => null, 'to' => '+598 2 900 0000'],
            ],
            'status' => SuggestionStatus::Pending,
            'is_owner_submission' => false,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'reason' => null,
            'place_edit_id' => null,
        ];
    }

    /** Already accepted by a moderator. */
    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => SuggestionStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }

    /** Declined, with a reason — the state the queue no longer shows. */
    public function rejected(string $reason = 'Not supported by any source.'): static
    {
        return $this->state(fn (): array => [
            'status' => SuggestionStatus::Rejected,
            'reviewed_at' => now(),
            'reason' => $reason,
        ]);
    }
}
