<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public profile shape (T-036, 03 §2.9). NEVER reuse the /me resource here:
 * email, role flags (beyond is_influencer), model preference and any billing
 * fields must not appear on a public surface. Counter keys are stable now —
 * follower counts populate with T-037.
 *
 * Callers must set `published_shares_count` (withCount alias) on the model.
 *
 * @mixin User
 */
class PublicUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'username' => $this->username,
            'name' => $this->name,
            'bio' => $this->bio,
            'avatar_path' => $this->avatar_path,
            'is_influencer' => (bool) $this->is_influencer,
            'counters' => [
                'published_shares' => (int) ($this->getAttribute('published_shares_count') ?? 0),
                'followers' => (int) ($this->getAttribute('followers_count') ?? 0),
                'following' => (int) ($this->getAttribute('following_count') ?? 0),
            ],
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
