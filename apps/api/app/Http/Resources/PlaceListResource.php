<?php

namespace App\Http\Resources;

use App\Models\PlaceList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A place list in index form (T-062): metadata + item count. The places
 * themselves are in {@see PlaceListDetailResource}. `items_count` comes from a
 * withCount on the query.
 *
 * @mixin PlaceList
 */
class PlaceListResource extends JsonResource
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
            'is_public' => (bool) $this->is_public,
            'items_count' => (int) ($this->items_count ?? $this->items()->count()),
            // Present only when the index was queried with ?contains={placeId}.
            'contains' => $this->when(isset($this->contains), fn () => (bool) $this->contains),
            'owner' => new UserSummaryResource($this->whenLoaded('user')),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
