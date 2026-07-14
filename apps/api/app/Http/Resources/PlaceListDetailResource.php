<?php

namespace App\Http\Resources;

use App\Models\PlaceList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A place list with its places (T-062). Each item carries the owner's note +
 * position plus a map-ready place summary, so a client can render the list and
 * drop pins without a second query. Expects `items.place` eager-loaded with
 * lat/lng selected (ST_Y/ST_X) — see PlaceListController.
 *
 * @mixin PlaceList
 */
class PlaceListDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'public_slug' => $this->public_slug,
            'is_public' => (bool) $this->is_public,
            // Attribute only owners who consented to public attribution — a
            // private-profile user who shares a list must not leak their
            // identity (matches FeedItemResource/PlaceSourceResource).
            'owner' => $this->whenLoaded(
                'user',
                fn () => $this->user->is_public ? new UserSummaryResource($this->user) : null,
            ),
            'items_count' => $this->items->count(),
            'items' => $this->items->map(fn ($item) => [
                'note' => $item->note,
                'position' => (int) $item->position,
                'place' => new PlaceSummaryResource($item->place),
            ])->values(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
