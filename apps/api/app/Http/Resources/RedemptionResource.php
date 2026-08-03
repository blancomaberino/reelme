<?php

namespace App\Http\Resources;

use App\Models\Redemption;
use App\Services\Redemptions\RedemptionCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A redemption on the wire (T-043, 03 §3.4).
 *
 * The `code` and `qr_payload` are the bearer credentials, so they are shown to
 * the DINER only. A restaurant verifying a code already has it in hand — echoing
 * it back would let the venue's redemption log double as a list of live codes
 * for offers it has not been presented with yet.
 *
 * `code_display` is the grouped form (`7F3K-92QX-AB`) so the client never
 * re-implements the formatting and the stored value stays bare.
 *
 * @mixin Redemption
 */
class RedemptionResource extends JsonResource
{
    /** Include the bearer credentials. Set only on the diner's own reads. */
    private bool $withCode = false;

    public function withCode(bool $with = true): static
    {
        $this->withCode = $with;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'offer_id' => (string) $this->offer_id,
            'status' => $this->status->value,
            // Computed, not read off the column: the expiry sweep runs on a
            // schedule, so a lapsed code still reads `issued` until it catches
            // up. The client must not offer a dead code to a till.
            'is_live' => $this->isLive(),
            'issued_at' => $this->issued_at->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'redeemed_at' => $this->redeemed_at?->toIso8601ZuluString(),

            'code' => $this->when($this->withCode, fn () => $this->code),
            'code_display' => $this->when($this->withCode, fn () => RedemptionCode::forDisplay($this->code)),
            'qr_payload' => $this->when($this->withCode, fn () => $this->qr_payload),

            // Frozen at issue (02 §5) — this is who earns from the visit, and it
            // does not move if the underlying share later changes or is deleted.
            'attribution' => [
                'influencer_id' => $this->attributed_influencer_id === null
                    ? null
                    : (string) $this->attributed_influencer_id,
                'share_id' => $this->attributed_share_id === null
                    ? null
                    : (string) $this->attributed_share_id,
            ],

            'offer' => $this->whenLoaded('offer', fn () => new OfferResource($this->offer)),
        ];
    }
}
