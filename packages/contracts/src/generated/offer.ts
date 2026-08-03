/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/offer.json
 */
/**
 * A restaurant offer (T-042, 03 §2.12) — GET /api/v1/offers, GET /api/v1/offers/{id}, and the ?include=offers embed on place detail. `discount_value` is one integer in three units, selected by `discount_type`: a percentage (5–50 via the API), MINOR currency units for fixed_amount (350 = €3.50), or an item count for free_item. Never a float — money in a float is a rounding bug waiting for the ledger to find it.
 */
export interface Offer {
  id: string;
  place_id: string;
  title: string;
  description: string | null;
  discount_type: 'percent' | 'fixed_amount' | 'free_item';
  discount_value: number;
  /**
   * Free-text terms shown to the diner before a redemption is issued (06 §2.2).
   */
  terms: string | null;
  starts_at: string;
  /**
   * Null = open-ended. The API defaults an omitted end date to starts_at + 90 days (06 §2.2 caps a run at 90 days), so null reaches clients only for rows created outside the public API.
   */
  ends_at: string | null;
  /**
   * Lifetime cap on redemptions; null = unlimited.
   */
  quota_total: number | null;
  quota_per_user: number;
  /**
   * Per-day cap (06 §2.2 anti-fraud throttle); null = unlimited.
   */
  quota_per_day: number | null;
  redemptions_count: number;
  /**
   * Redemptions left under quota_total; null when unlimited — a distinction a raw subtraction cannot express.
   */
  remaining_quota: number | null;
  status: 'draft' | 'active' | 'paused' | 'expired' | 'archived';
  /**
   * Computed, not derived from `status`: an offer whose window lapsed overnight still reads status=active. Covers the window and the lifetime quota; the per-day quota is settled at issue time.
   */
  is_redeemable: boolean;
  /**
   * Compact venue block, present only on the flat /offers reads — never on the ?include=offers embed, where the caller already holds the place.
   */
  place?: {
    id: string;
    name: string;
    slug: string;
    city: string | null;
    /**
     * Venue latitude. Present on the flat /offers reads so the diner browse can map them; null when the query did not select coordinates.
     */
    lat: number | null;
    /**
     * Venue longitude. See lat.
     */
    lng: number | null;
    country_code: string | null;
    thumbnail_url: string | null;
  };
  created_at: string | null;
  updated_at: string | null;
}
