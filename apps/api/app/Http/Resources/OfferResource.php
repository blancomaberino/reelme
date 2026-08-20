<?php

namespace App\Http\Resources;

use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An offer on the wire (T-042, 03 §2.12) — the same shape for the diner browsing
 * it and the operator managing it.
 *
 * Two fields are computed rather than echoed, because the columns alone are
 * misleading:
 *
 * - `is_redeemable` — `status` is only an operator's intent (an offer whose
 *   window lapsed overnight still reads `active`), so the client is told the
 *   answer from {@see Offer::isRedeemable()} instead of being invited to derive
 *   it and get it wrong. The per-day quota is not consulted here: it needs
 *   today's redemption rows, which arrive with T-043, so this answers "live and
 *   not sold out", and the issue endpoint remains the authority at issue time.
 * - `remaining_quota` — null means unlimited, which a raw
 *   `quota_total - redemptions_count` cannot express.
 *
 * `redemptions_count` is exposed because it is the operator's headline number
 * and, on a live offer, the diner's scarcity signal. It counts the slots the
 * offer currently HOLDS — codes still `issued` as well as ones honoured
 * (T-127) — so it is always ≥ the billable count, which is `redeemed` alone
 * (06 §2.3). Never a count of anyone's identity.
 *
 * @mixin Offer
 */
class OfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'place_id' => (string) $this->place_id,
            'title' => $this->title,
            'description' => $this->description,
            'discount_type' => $this->discount_type->value,
            // Unit depends on discount_type — percent, minor units, or an item
            // count (see OfferDiscountType). Always an integer, never money in
            // a float.
            'discount_value' => $this->discount_value,
            'terms' => $this->terms,
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'ends_at' => $this->ends_at?->toIso8601ZuluString(),
            'quota_total' => $this->quota_total,
            'quota_per_user' => $this->quota_per_user,
            'quota_per_day' => $this->quota_per_day,
            'redemptions_count' => $this->redemptions_count,
            'remaining_quota' => $this->remainingQuota(),
            'status' => $this->status->value,
            'is_redeemable' => $this->isRedeemable(),

            /*
             * A compact venue block, present only where the caller does not
             * already have the place in hand — the flat browse, not the
             * `?include=offers` embed on the place itself.
             *
             * Deliberately not PlaceSummaryResource: that shape requires `lat`
             * / `lng` selected as SQL aliases by the caller's query, and an
             * offer list has no reason to inherit that coupling.
             */
            'place' => $this->whenLoaded('place', fn () => [
                'id' => (string) $this->place->id,
                'name' => $this->place->name,
                'slug' => $this->place->slug,
                'city' => $this->place->city,
                // Present only when the caller's query aliased them (the flat
                // browse does; the place-detail embed has no need). Null rather
                // than absent so the map can skip a venue it cannot place
                // instead of the client having to feature-detect the field.
                'lat' => isset($this->place->lat) ? (float) $this->place->lat : null,
                'lng' => isset($this->place->lng) ? (float) $this->place->lng : null,
                'country_code' => $this->place->country_code,
                'thumbnail_url' => $this->place->thumbnail_url ?? $this->place->image_url,
            ]),

            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
