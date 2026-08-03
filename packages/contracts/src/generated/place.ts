/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/place.json
 */
/**
 * GET /api/v1/places/{slug} data payload (T-030, 03 §2.6). `sources` appears only with ?include=sources (place-source.json items); `offers` only with ?include=offers (offer.json items, live ones only — T-042). `my_tags` appears only for the authed owner (T-064) — the caller's private per-user labels, never present for guests or other users.
 */
export interface PlaceDetail {
  id: string;
  name: string;
  slug: string;
  status: 'pending' | 'active';
  /**
   * Whether a restaurant operator has a verified claim on this place (T-041). Who the operator is stays private; this only says the venue is claimed.
   */
  claimed: boolean;
  lat: number;
  lng: number;
  category: string | null;
  price_range: number | null;
  city: string | null;
  country_code: string;
  address: string;
  google_place_id: string | null;
  opening_hours: {} | unknown[] | null;
  phone: string | null;
  website: string | null;
  /**
   * Curated business photo (T-084); the detail hero, else the client falls back to the reel poster.
   */
  image_url: string | null;
  /**
   * Curated marker photo (T-084); the map marker prefers it, falling back to image_url then the reel poster.
   */
  thumbnail_url: string | null;
  /**
   * Ordered business photo gallery (T-099): owned website (schema.org) images first, then business-attributed Google photos, then fill. image_url mirrors gallery[0]. The client shows a carousel only when length > 1.
   */
  gallery: {
    /**
     * Client-loadable http(s) image URL (no API key).
     */
    url: string;
    /**
     * Where the photo came from: the business's own website, Google Places, or the reel-derived thumbnail (last-resort).
     */
    source: 'website' | 'google' | 'reel';
    /**
     * Uploader/attribution text (Google html_attributions, tags stripped); null for owned website images.
     */
    attribution: string | null;
  }[];
  cuisines: string[];
  vibe_tags: string[];
  dietary_tags: string[];
  dishes: {
    name: string;
    shown_in_video: boolean;
    price: string | null;
  }[];
  /**
   * When the dish/menu list was last refreshed by a source (ISO 8601).
   */
  dishes_updated_at: string | null;
  /**
   * BCP-47 language of the menu source; dish names are kept verbatim in this language so the client can label it.
   */
  dishes_language: string | null;
  source_count: number;
  rating: {
    google: RatingBlock;
    app: RatingBlock;
  };
  google_reviews: {
    author?: string | null;
    rating?: number | null;
    text?: string | null;
    relative_time?: string | null;
    time?: number | null;
    profile_photo_url?: string | null;
  }[];
  /**
   * Multi-source review aggregate (T-082): one normalized row per resolving provider (`native`, `google`, `trustpilot`, …), in display order. A provider with no resolvable id for the place is omitted (no empty rows). `rating` is a 0–5 average; `url` deep links to the full reviews on that source (null for the intrinsic native source); `synced_at` is when external content was last fetched. Coexists with the back-compat `rating.google`/`rating.app`/`google_reviews`.
   */
  review_sources: {
    /**
     * Provider id / UI label key, e.g. "google".
     */
    source: string;
    rating: number | null;
    count: number;
    /**
     * Deep link to the full reviews on the source; null for native.
     */
    url: string | null;
    /**
     * ISO 8601 time the external summary was last fetched; null for computed sources.
     */
    synced_at: string | null;
    snippets: {
      author: string | null;
      rating: number | null;
      text: string | null;
      relative_time: string | null;
      profile_photo_url: string | null;
    }[];
  }[];
  /**
   * Card/bank/wallet payment discounts mentioned across the place's sources (T-079), aggregated + deduped. `card` is the display label (resolved issuer, else scheme, else @handle); filter the map/index by it via ?card=.
   */
  discounts: {
    /**
     * Display label of the paying card/bank/wallet.
     */
    card: string;
    /**
     * The benefit as stated, e.g. "20% off".
     */
    terms: string;
    percent: number | null;
  }[];
  sources?: PlaceSource[];
  /**
   * Live offers only (T-042) — the embed is filtered by the active() scope, so drafts, paused promos, and lapsed windows never appear here. The `place` block is omitted: the caller already holds it.
   */
  offers?: Offer[];
  /**
   * Owner-only private per-user tags (T-064). Present only when the caller is authenticated; absent for guests. Never contains another user's labels.
   */
  my_tags?: {
    id: string;
    label: string;
    created_at: string | null;
  }[];
}
export interface RatingBlock {
  value: number | null;
  count: number;
}
/**
 * One attribution row of GET /api/v1/places/{slug}/sources and the ?include=sources embed (T-030): original post link-out, influencer and (public-only) sharer attribution, extraction highlights.
 */
export interface PlaceSource {
  id: string;
  is_primary: boolean;
  source_post: {
    platform: string;
    url: string | null;
    caption: string | null;
    posted_at: string | null;
    thumbnail_url: string | null;
  } | null;
  /**
   * Null when the post has no linked influencer (03 §2.6).
   */
  influencer: null | InfluencerSummary;
  /**
   * Null when the sharer's profile is private (03 §2.6).
   */
  sharer: null | UserSummary;
  highlights: {
    dishes: string[];
    tags: string[];
  };
}
/**
 * Compact influencer attribution block (03 §2.6) — the shape of InfluencerSummaryResource, embedded in feed rows and place-source rows. Never the full influencer profile (see influencer-profile.json).
 */
export interface InfluencerSummary {
  id: string;
  platform: string;
  handle: string;
  display_name: string | null;
  avatar_url: string | null;
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
    country_code: string | null;
    thumbnail_url: string | null;
  };
  created_at: string | null;
  updated_at: string | null;
}
