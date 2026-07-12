<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public review shape (T-059). The author is withheld (null) when they are not
 * a public profile — same policy as sharer attribution on sources.
 *
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $author = $this->user;

        return [
            'id' => (string) $this->id,
            'rating' => $this->rating,
            'body' => $this->body,
            'author' => ($author === null || ! $author->is_public)
                ? null
                : new UserSummaryResource($author),
            'is_own' => $request->user('sanctum')?->id === $this->user_id,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
