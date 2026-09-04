// Discovery-domain API types (places, sources, map, feed, search).
//
// Shapes that have a JSON Schema in packages/contracts are RE-EXPORTED from
// @reelmap/contracts (T-094, T-102), so a renamed/removed API field breaks these
// at typecheck time, not on-device: PlaceSummary, UserSummary and FeedItem here,
// UserProfile in ./profile.ts, ShareDetail in ./shares.ts, the list shapes in
// ./lists.ts. What stays hand-written below is what has no schema yet (the
// map/search rows). PlaceDetail is still spelled out here because several of its
// fields are optional for older cached payloads, but every field the contract
// pins is DERIVED from it (opening_hours, reviews) rather than restated — a
// restated field is exactly how `opening_hours` drifted (T-128).
import type { Offer } from './offers';

import type {
  FeedItem as ContractFeedItem,
  InfluencerSummary as ContractInfluencerSummary,
  PlaceDetail as ContractPlaceDetail,
  PlaceSummary as ContractPlaceSummary,
  Review as ContractReview,
  UserSummary as ContractUserSummary,
} from '@reelmap/contracts';

/** Google/native rating pair — the contract's shared rating block. */
export type RatingBlock = ContractPlaceSummary['rating']['google'];

/** Attribution glyph on a source card / pin (SourcePost.influencer). */
export type InfluencerSummary = ContractInfluencerSummary;

/**
 * The user who shared a post (UserSummaryResource). Null when their profile is
 * private — the API omits the identity rather than anonymizing it.
 */
export type SharerSummary = ContractUserSummary | null;

/** A person row from `/search` (UserSummaryResource) — taps through to /users/[username]. */
export type UserSummary = ContractUserSummary;

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

/**
 * A native (in-app) review — place detail `reviews` (?include=reviews) and the
 * body of PUT /places/{place}/reviews. Derived from review.json (T-128): the
 * hand-written version had already lost `updated_at` and the author's `name`,
 * both of which ReviewResource sends.
 */
export type AppReview = ContractReview;

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
  /**
   * The street line on its own, beside the joined display string above (T-083).
   * The suggest-an-edit form corrects this field and cannot parse it back out
   * of "Calle X, Montevideo, UY". Optional so older cached payloads still type.
   */
  address_line1?: string | null;
  /**
   * Whether THIS viewer may edit the place directly — a verified operator
   * (T-041/T-083). Everyone else's changes go to the moderation queue, so this
   * chooses between "edit" and "suggest a change", never whether the control
   * appears. Optional for older cached payloads; absent reads as false.
   */
  can_edit?: boolean;
  google_place_id: string | null;
  /**
   * Human-readable opening-hour lines, one rule per entry, rendered verbatim —
   * the wording and language are the source's. A flat list of strings, which is
   * what every API writer stores; see {@link hourLines}.
   */
  opening_hours: ContractPlaceDetail['opening_hours'];
  /**
   * Whether the venue is open right now — decided by the API from structured
   * periods and the venue's own timezone (T-155). NULL means unknowable, and
   * the screen must then show {@link opening_hours} with NO status cue; it is
   * never rendered as "Closed". The periods and timezone themselves are not
   * served on purpose: one implementation decides this, and it is not this one.
   *
   * OPTIONAL as well as nullable, for the same reason as {@link can_edit}: the
   * query cache is persisted, so a payload cached before this field existed
   * rehydrates with the key ABSENT, not null. Typing it as always-present would
   * let a future call site write `place.open_state.open_now` with no compiler
   * complaint and crash on the first upgrade from an older build.
   */
  open_state?: ContractPlaceDetail['open_state'];
  phone: string | null;
  website: string | null;
  // Curated business picture (T-084): the main image drives the detail hero
  // (else we fall back to the reel poster); the thumbnail is the marker photo.
  image_url: string | null;
  thumbnail_url: string | null;
  /**
   * Ordered business photo gallery (T-099): owned website images first, then
   * business-attributed Google photos, then fill. `image_url` mirrors
   * `gallery[0]`; the detail shows a swipeable carousel only when length > 1.
   * Optional here so older cached payloads still type.
   */
  gallery?: PlaceGalleryImage[];
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
  reviews?: ContractPlaceDetail['reviews'];
  sources?: PlaceSourceItem[];
  /**
   * The venue's LIVE offers (T-042 `?include=offers`). The API filters to the
   * `active()` scope, so drafts, paused promos and lapsed windows never reach
   * here — a section built from this cannot advertise something a till would
   * refuse. Capped server-side; the full list is the offers browse.
   */
  offers?: Offer[];
  /**
   * The viewer's own private tags (T-064). Present only when authenticated;
   * absent for guests, and never carries another user's labels.
   */
  my_tags?: MyPlaceTag[];
};

/** One image in a place's business gallery (T-099). */
export type PlaceGalleryImage = {
  url: string;
  source: 'website' | 'google' | 'reel';
  /** Uploader/attribution text (Google photos); null for owned website images. */
  attribution: string | null;
};

/** A private, owner-only label the viewer pinned to a place (T-064). */
export type MyPlaceTag = {
  id: string;
  label: string;
  created_at?: string | null;
};

/**
 * Opening hours as the API stores and serves them: a flat list of human-readable
 * rule lines. Derived from the contract, never restated (T-128).
 */
export type OpeningHours = NonNullable<ContractPlaceDetail['opening_hours']>;
export type OpenState = NonNullable<ContractPlaceDetail['open_state']>;

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
  /**
   * Metres from the viewer, straight-line, computed by PostGIS (T-156).
   *
   * OPTIONAL, and that is the contract: the key is ABSENT when the request
   * carried no `near`, never 0. Zero is a real distance ("you are standing in
   * it"), so a default would be indistinguishable from the truth. Read it with
   * `!= null`, never as a truthy check — a place 0 m away would vanish.
   */
  distance_m?: number;
  /**
   * Open-or-closed as the server decided it, from the venue's structured periods
   * and its OWN timezone (T-155). Unlike `distance_m` it is present WHETHER OR
   * NOT the viewer shared a position — open-or-closed is a fact about the venue,
   * not about the viewer — and null within a response when the answer is not
   * knowable, which must render as no cue at all, never as "Closed". Rendered
   * through `openStateLabel()`, the same helper the place detail uses.
   *
   * Optional only because a cache persisted before T-156 can be replayed without
   * it; a live response from a current server always carries the key.
   */
  open_state?: OpenState | null;
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

/**
 * One row of GET /feed (FeedItemResource), derived from the schema (T-102).
 * `sharer`, `source_post`, `influencer` and `place` are all independently
 * nullable — a row survives a private sharer or a lost post/place.
 */
export type FeedItem = ContractFeedItem;

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
