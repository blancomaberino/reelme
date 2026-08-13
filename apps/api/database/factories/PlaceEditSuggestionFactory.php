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
            'note' => null,
            'status' => SuggestionStatus::Pending,
            'is_owner_submission' => false,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'reason' => null,
            'place_edit_id' => null,
        ];
    }

    /**
     * "Something else is wrong" and nothing else (T-112) — prose, no patch.
     *
     * `changes` is emptied on purpose: a note-only row stores `{}`, and every
     * renderer has to survive that. A state that left the default phone diff in
     * place would produce a row no submit path can create.
     */
    public function noteOnly(string $note = 'This place closed down last month.'): static
    {
        return $this->state(fn (): array => [
            'changes' => [],
            'note' => $note,
        ]);
    }

    /** Dealt with by hand — the verb a note-only row settles with. */
    public function actioned(string $reason = 'Confirmed closed by phone; hid the place.'): static
    {
        return $this->state(fn (): array => [
            'status' => SuggestionStatus::Actioned,
            'reviewed_at' => now(),
            'reason' => $reason,
        ]);
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
