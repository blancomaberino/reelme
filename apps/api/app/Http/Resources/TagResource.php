<?php

namespace App\Http\Resources;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public tag shape (T-031, 03 §2.11). `places_count` appears when the query
 * counted usage (the ?popular=1 ordering).
 *
 * @mixin Tag
 */
class TagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'kind' => $this->kind->value,
            'name' => $this->name,
            'slug' => $this->slug,
            'places_count' => $this->when(
                $this->getAttribute('places_count') !== null,
                fn () => (int) $this->places_count,
            ),
        ];
    }
}
