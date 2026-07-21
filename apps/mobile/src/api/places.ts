// Discovery-domain API types (places, sources, map, feed, search).
//
// Shapes that have a JSON Schema in packages/contracts are RE-EXPORTED from
// @reelmap/contracts (T-094), so a renamed/removed API field breaks these at
// typecheck time, not on-device: PlaceSummary here, UserProfile in ./profile.ts.
// The remaining types (map/feed/search rows, and PlaceDetail — pending the
// schema gaps in place.json, e.g. the ?include=reviews payload) stay hand-written
// and mirror the live API resources; migrate them as their schemas gain the
// missing fields (tracked with CI T-006).
import type { PlaceSummary as ContractPlaceSummary } from '@reelmap/contracts';

/** Google/native rating pair — the contract's shared rating block. */
export type RatingBlock = ContractPlaceSummary['rating']['google'];

/** Attribution glyph on a source card / pin (SourcePost.influencer). */
export type InfluencerSummary = {
  id: string;
  platform: string;
  handle: string;
  display_name: string | null;
  avatar_url: string | null;
};

/** The user who shared a post (UserSummaryResource; anonymized when private). */
export type SharerSummary = {
  id: string;
  username: string;
  name: string;
  avatar_path: string | null;
} | null;

/** A person row from `/search` (UserSummaryResource) — taps through to /users/[username]. */
export type UserSummary = {
  id: string;
  username: string;
  name: string | null;
  avatar_path: string | null;
};

export type SocialPlatform = 'instagram' | 'x' | 'tiktok' | 'youtube';

/**
 * One row of GET /places, /search places, /me/places, and feed `place`
 * (PlaceSummaryResource). Derived from the schema — the single source of truth
 * shared with the API (T-094).
 */
export type PlaceSummary = ContractPlaceSummary;

export type Dish = {
  name: string;
  shown_in_video: boolean;
  /** Menu price exactly as shown (with currency symbol), or null if none seen. */
  price: string | null;
};

/** A card/bank/wallet payment discount mentioned in a source (T-079). */
export type Discount = {
  /** Display label: resolved issuer, else scheme, else @handle. */
  card: string;
  /** The benefit as stated, e.g. "20% off". */
  terms: string;
  percent: number | null;
};

/** A Google-cached review snippet (place detail `google_reviews`). */
export type GoogleReview = {
  author: string | null;
  rating: number | null;
  text: string | null;
  relative_time?: string | null;
  time?: number | null;
  profile_photo_url?: string | null;
};

/** One normalized excerpt inside a `ReviewSourceSummary` (T-082). */
export type ReviewSnippet = {
  author: string | null;
  rating: number | null;
  text: string | null;
  relative_time: string | null;
  profile_photo_url: string | null;
};

/**
 * One provider's contribution to the multi-source review aggregate (T-082) —
 * a row in `review_sources[]`. `source` is the driver id / label key ('google',
 * 'native', 'trustpilot', …); `url` deep links to the full reviews on that
 * source (null for the intrinsic native source); `synced_at` is when external
 * content was last fetched.
 */
export type ReviewSourceSummary = {
  source: string;
  rating: number | null;
  count: number;
  url: string | null;
  synced_at: string | null;
  snippets: ReviewSnippet[];
};

/** A native (in-app) review (place detail `reviews`, via ?include=reviews). */
export type AppReview = {
  id: string;
  rating: number;
  body: string | null;
  author: { username: string; avatar_path: string | null } | null;
  is_own: boolean;
  created_at: string | null;
};

/**
 * One place_source on the detail screen — the provenance card. `source_post`
 * links out to the original reel; influencer + sharer carry attribution.
 */
export type PlaceSourceItem = {
  id: string;
  is_primary: boolean;
  source_post: {
    platform: string;
    url: string;
    caption: string | null;
    posted_at: string | null;
    thumbnail_url: string | null;
  };
  influencer: InfluencerSummary | null;
  sharer: SharerSummary;
  highlights: {
    dishes: string[];
    tags: string[];
  };
};

/** GET /places/{slug}?include=sources (PlaceResource). */
export type PlaceDetail = {
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
  address: string | null;
  google_place_id: string | null;
  opening_hours: OpeningHours | null;
  phone: string | null;
  website: string | null;
  // Curated business picture (T-084): the main image drives the detail hero
  // (else we fall back to the reel poster); the thumbnail is the marker photo.
  image_url: string | null;
  thumbnail_url: string | null;
  cuisines: string[];
  vibe_tags: string[];
  dietary_tags: string[];
  dishes: Dish[];
  /** When the dish/menu list was last refreshed by a source (ISO 8601). */
  dishes_updated_at: string | null;
  /** BCP-47 language of the menu source; dish names are verbatim in it. */
  dishes_language: string | null;
  source_count: number;
  rating: { google: RatingBlock; app: RatingBlock };
  /**
   * Multi-source review aggregate (T-082): one normalized row per resolving
   * provider, in display order. Coexists with the back-compat `rating`/
   * `google_reviews`; a provider with no resolvable id is omitted. Always
   * present on the live API; optional here so older cached payloads still type.
   */
  review_sources?: ReviewSourceSummary[];
  /** Card/bank/wallet payment discounts across the place's sources (T-079). */
  discounts: Discount[];
  google_reviews?: GoogleReview[];
  reviews?: AppReview[];
  sources?: PlaceSourceItem[];
  /**
   * The viewer's own private tags (T-064). Present only when authenticated;
   * absent for guests, and never carries another user's labels.
   */
  my_tags?: MyPlaceTag[];
};

/** A private, owner-only label the viewer pinned to a place (T-064). */
export type MyPlaceTag = {
  id: string;
  label: string;
  created_at?: string | null;
};

/**
 * Google-style opening hours: `periods` are weekly open/close windows keyed by
 * day-of-week (0 = Sunday). Shape mirrors the Places API `opening_hours` we cache.
 */
export type OpeningHours = {
  periods?: {
    open: { day: number; time: string };
    close?: { day: number; time: string };
  }[];
  weekday_text?: string[];
};

// --- Map ---

export type MapPin = {
  type: 'place';
  id: string;
  name: string;
  lat: number;
  lng: number;
  category: string | null;
  city: string | null;
  price_range: number | null;
  status: string;
  tags: string[];
  source_count: number;
  has_active_offer: boolean;
  /** The primary reel's poster — drawn inside the map marker; null when the source has no imagery. */
  thumbnail_url: string | null;
  top_influencer: { handle: string; display_name: string | null } | null;
};

export type MapCluster = {
  type: 'cluster';
  cluster_id: string;
  lat: number;
  lng: number;
  count: number;
  expand: { bbox: [number, number, number, number] };
};

export type MapResponse = {
  data: { pins: MapPin[]; clusters: MapCluster[] };
  meta: { zoom: number; total_in_bbox: number; clustered: boolean; truncated?: boolean };
};

// --- Feed ---

export type FeedItem = {
  id: string;
  published_at: string | null;
  sharer: SharerSummary;
  source_post: {
    platform: string;
    url: string;
    caption: string | null;
    thumbnail_url: string | null;
  };
  influencer: InfluencerSummary | null;
  place: PlaceSummary;
};

export type Pagination = {
  next_cursor: string | null;
  prev_cursor: string | null;
  limit: number;
};

export type Paginated<T> = {
  data: T[];
  meta: { pagination: Pagination } & Record<string, unknown>;
};

// --- Search ---

export type TagSummary = {
  id: string;
  kind: string;
  /** Canonical English name. */
  name: string;
  /** Name localized to the request locale (ADR-084 #2); falls back to `name`. */
  label?: string;
  slug: string;
  places_count?: number;
};

/**
 * Federated search payload. The People search (T-077) requests places/users/tags
 * — influencer results were an inert placeholder (no profile route yet) and are
 * no longer requested; `InfluencerSummary` lives on for feed/place attribution.
 */
export type SearchResponse = {
  data: {
    places: PlaceSummary[];
    users: UserSummary[];
    tags: TagSummary[];
  };
  meta: { query: string; took_ms: number };
};
