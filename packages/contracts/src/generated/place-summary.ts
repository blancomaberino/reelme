/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/place-summary.json
 */
/**
 * One row of GET /api/v1/places (T-030) — the browse/list card.
 */
export interface PlaceSummary {
  id: string;
  name: string;
  slug: string;
  status: 'pending' | 'active';
  lat: number;
  lng: number;
  category: string | null;
  price_range: number | null;
  city: string | null;
  country_code: string;
  thumbnail_url?: string | null;
  mine?: {
    share_id: string | null;
    saved: boolean;
  };
  source_count: number;
  /**
   * Whether the place has an offer redeemable RIGHT NOW (T-047). Present only on listings that selected it (my-places, map pins); absent — not false — elsewhere, since a listing that never looked must not claim there is nothing. Gated on the active() scope, never the status column, which nothing rewrites when a window closes.
   */
  has_active_offer?: boolean;
  rating: {
    google: RatingBlock;
  };
  distance_m: number | null;
  created_at: string | null;
}
export interface RatingBlock {
  value: number | null;
  count: number;
}
