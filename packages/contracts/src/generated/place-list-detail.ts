/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/place-list-detail.json
 */
/**
 * A saved collection with its places (T-062) — GET /api/v1/me/lists/{id}, the add/remove responses, and the public read GET /api/v1/lists/{public_slug} (T-063). Each item carries the owner's note + position plus a map-ready place summary, so a client can render the list and drop its pins without a second query.
 */
export interface PlaceListDetail {
  id: string;
  name: string;
  slug: string;
  public_slug: string | null;
  is_public: boolean;
  /**
   * Present only when the owner was eager-loaded (the public read); null when their profile is private — a private user sharing a list must not leak their identity.
   */
  owner?: null | UserSummary;
  items_count: number;
  items: {
    note: string | null;
    position: number;
    place: PlaceSummary;
  }[];
  created_at: string | null;
  updated_at: string | null;
}
/**
 * Compact public attribution block (03 §2.6) — the shape of UserSummaryResource, embedded wherever a sharer or list owner is credited. Only users who consented to public attribution are wrapped; a private user is represented as `null`, never as an anonymized stub.
 */
export interface UserSummary {
  id: string;
  username: string;
  name: string | null;
  avatar_path: string | null;
}
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
    google: {
      value: number | null;
      count: number;
    };
  };
  distance_m: number | null;
  /**
   * Whether the venue is open RIGHT NOW, computed server-side from structured periods and the venue's IANA timezone (T-155). NULL means the answer is not knowable — no structured periods, or no timezone — and the client must then show the `opening_hours` lines with NO status cue. Null is never to be rendered as "Closed": a confidently wrong "Closed" sends someone away from a restaurant that is open. The periods and the timezone themselves are deliberately NOT served: one implementation decides this (App\Support\OpeningSchedule) and the client renders its answer, because shipping a second parseable copy of the week is how the client came to invent its own reading in T-128. Google's own `open_now` is never forwarded — it is true at fetch time and a lie for the 30 days the response is cached.
   */
  open_state: {
    open_now: boolean;
    /**
     * Venue-local wall clock at which the current opening period ends. Null while closed, and also null for a venue that never closes.
     */
    closes_at: string | null;
    /**
     * Venue-local wall clock of the next opening, and ONLY when it falls on the same local day. Null while open, and null when the next opening is tomorrow — "opens 11:00" without a weekday would read as "in an hour", and rendering the weekday belongs to the client's locale.
     */
    opens_at: string | null;
  } | null;
  created_at: string | null;
}
