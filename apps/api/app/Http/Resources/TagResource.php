<?php

namespace App\Http\Resources;

use App\Models\Tag;
use App\Support\RequestLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public tag shape (T-031, 03 §2.11). `name` is the canonical English label;
 * `label` is it localized to the request locale (ADR-084 #2) — clients display
 * `label` and keep filtering on `slug`. `places_count` appears when the query
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
            'label' => $this->localizedName(RequestLocale::resolve($request)),
            'slug' => $this->slug,
            'places_count' => $this->when(
                $this->getAttribute('places_count') !== null,
                fn () => (int) $this->places_count,
            ),
        ];
    }
}
