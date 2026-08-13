<?php

namespace App\Http\Resources;

use App\Models\PlaceEditSuggestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A proposed place edit (T-083) — the submit response, and the row in an
 * operator's "suggested for your venue" list.
 *
 * The submitter is deliberately NOT exposed. An operator needs to judge the
 * PROPOSAL, and handing them the username of every diner who corrected their
 * phone number turns a helpful edit into an identifiable complaint about a
 * business — the same reasoning that keeps claimants out of the claim payload.
 * The moderation queue in Filament shows the submitter; the venue does not.
 *
 * @mixin PlaceEditSuggestion
 */
class PlaceEditSuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'place_id' => (string) $this->place_id,
            // Named so a cross-venue list can say which restaurant it is about
            // without a second request. Loaded by the controller; absent rather
            // than lazily fetched, so a missing eager-load is visible instead of
            // silently costing an N+1.
            'place' => $this->whenLoaded('place', fn (): array => [
                'id' => (string) $this->place->id,
                'name' => $this->place->name,
                'slug' => $this->place->slug,
            ]),
            'status' => $this->status->value,
            // Whether this row was an operator's own edit (applied on submit)
            // rather than a proposal awaiting review.
            'is_owner_submission' => $this->is_owner_submission,
            // A LIST, not the stored map: JSON object key order is not something
            // a client may rely on, and the two surfaces that render this both
            // show the fields in a fixed order.
            'changes' => $this->changeList(),
            // The submitter's own words (T-112) — "this place closed down". For
            // a note-only row this IS the proposal, and `changes` is empty, so
            // both surfaces have to render it or show the operator a blank card.
            //
            // Yes, this puts unmoderated free text in front of the venue: the
            // operator list is pending-only, so they see it before a moderator
            // does. That is the trade taken on purpose — the operator is the one
            // person who can answer "did this place close down", and holding the
            // note back until review makes the whole feature slower than the
            // thing it replaces. It stays bounded (2000 chars), attributable,
            // and on the `reviews` limiter, and the SUBMITTER is still hidden,
            // so a note cannot be used to put a name in front of a business.
            'note' => $this->note,
            'created_at' => $this->created_at->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }

    /**
     * `{field: {from, to}}` → `[{field, from, to}]`, in the allow-list's order so
     * every surface reads the same way round.
     *
     * @return list<array{field: string, from: mixed, to: mixed}>
     */
    private function changeList(): array
    {
        $changes = $this->changes;
        $out = [];

        foreach (PlaceEditSuggestion::FIELDS as $field) {
            $change = $changes[$field] ?? null;
            if (! is_array($change)) {
                continue;
            }

            $out[] = [
                'field' => $field,
                'from' => $change['from'] ?? null,
                'to' => $change['to'] ?? null,
            ];
        }

        return $out;
    }
}
