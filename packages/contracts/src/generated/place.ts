/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/place.json
 */
/**
 * GET /api/v1/places/{slug} data payload (T-030, 03 §2.6). `sources` appears only with ?include=sources (place-source.json items); `offers` only with ?include=offers (empty until M4). `my_tags` appears only for the authed owner (T-064) — the caller's private per-user labels, never present for guests or other users.
 */
export interface PlaceDetail {
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
  address: string;
  google_place_id: string | null;
  opening_hours: {} | unknown[] | null;
  phone: string | null;
  website: string | null;
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
  sources?: PlaceSource[];
  offers?: unknown[];
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
  influencer: {
    id: string;
    platform: string;
    handle: string;
    display_name: string | null;
    avatar_url: string | null;
  } | null;
  sharer: {
    id: string;
    username: string;
    name: string | null;
    avatar_path: string | null;
  } | null;
  highlights: {
    dishes: string[];
    tags: string[];
  };
}
