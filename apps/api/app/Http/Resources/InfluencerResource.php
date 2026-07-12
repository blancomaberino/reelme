<?php

namespace App\Http\Resources;

use App\Models\Influencer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full public influencer profile (T-036, 03 §2.9). `claimed_by_user_id` is
 * never serialized raw — `claimed` is a boolean, plus the claimer's public
 * username when (and only when) that account is public.
 *
 * Callers must set `promoted_places_count` on the model.
 *
 * @mixin Influencer
 */
class InfluencerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $claimer = $this->claimedBy;

        return [
            'id' => (string) $this->id,
            'platform' => $this->platform->value,
            'handle' => $this->handle,
            'display_name' => $this->display_name,
            'avatar_url' => $this->avatar_url,
            'claimed' => $this->claimed_by_user_id !== null,
            'claimed_by' => ($claimer !== null && $claimer->is_public) ? $claimer->username : null,
            'follower_count' => (int) ($this->follower_count_cached ?? 0),
            'counters' => [
                'promoted_places' => (int) ($this->getAttribute('promoted_places_count') ?? 0),
            ],
        ];
    }
}
