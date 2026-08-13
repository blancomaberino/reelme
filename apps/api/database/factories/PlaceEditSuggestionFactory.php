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

    /** The note a note-only state writes when the caller does not supply one. */
    public const DEFAULT_NOTE = 'This place closed down last month.';

    /**
     * "Something else is wrong" and nothing else (T-112) — prose, no patch.
     *
     * `changes` is emptied on purpose, and what lands on disk is `[]`, not `{}`:
     * the column goes through Eloquent's `array` cast, and PHP encodes an empty
     * array as a JSON list. Every renderer AND every query has to survive that
     * shape — the GDPR purge matched only `{}` at first and therefore deleted
     * nothing. A state that left the default phone diff in place would produce a
     * row no submit path can create.
     */
    public function noteOnly(string $note = self::DEFAULT_NOTE): static
    {
        return $this->state(fn (): array => [
            'changes' => [],
            'note' => $note,
        ]);
    }

    /**
     * Dealt with by hand — the verb a note-only row settles with.
     *
     * Empties the patch and guarantees a note, so the state stands ALONE as a
     * row the workflow could actually have produced: `PlaceSuggestionService::
     * action()` refuses anything that is not note-only, so an `actioned()` row
     * still carrying the default phone diff would be a fixture no code path can
     * mint — the kind of test data that makes a later assertion pass for a
     * reason nobody intended.
     *
     * A note from a preceding `noteOnly()` wins; this only fills the gap.
     */
    public function actioned(string $reason = 'Confirmed closed by phone; hid the place.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SuggestionStatus::Actioned,
            'reviewed_at' => now(),
            'reason' => $reason,
            'changes' => [],
            'note' => filled($attributes['note'] ?? null) ? $attributes['note'] : self::DEFAULT_NOTE,
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
