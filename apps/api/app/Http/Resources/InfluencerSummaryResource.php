<?php

namespace App\Http\Resources;

use App\Models\Influencer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact influencer attribution block embedded in place/source payloads
 * (03 §2.6) — never the full influencer profile.
 *
 * @mixin Influencer
 */
class InfluencerSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'platform' => $this->platform->value,
            'handle' => $this->handle,
            'display_name' => $this->display_name,
            'avatar_url' => $this->avatar_url,
        ];
    }
}
