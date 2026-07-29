<?php

namespace App\Http\Resources;

use App\Models\InfluencerClaim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The caller's own claim state (T-038) — drives the mobile claim/resume flow.
 * `token` is the caller's own one-time code (they placed it, so returning it is
 * safe) and is null once the claim verifies or for the OAuth path.
 *
 * @mixin InfluencerClaim
 */
class InfluencerClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'influencer_id' => (string) $this->influencer_id,
            'status' => $this->status->value,
            'method' => $this->method->value,
            'token' => $this->token,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
