<?php

namespace App\Http\Resources;

use App\Models\UserPlaceTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single private per-user place tag (T-064). Owner-only — this shape only
 * ever reaches the user who created the tag (the place detail attaches it via
 * `my_tags`, guarded on the authed caller). IDs serialize as strings (03 §1).
 *
 * @mixin UserPlaceTag
 */
class UserPlaceTagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'label' => $this->label,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
