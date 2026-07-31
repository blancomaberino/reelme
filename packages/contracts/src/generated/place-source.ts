/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/place-source.json
 */
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
 * Compact public attribution block (03 §2.6) — the shape of UserSummaryResource, embedded wherever a sharer or list owner is credited. Only users who consented to public attribution are wrapped; a private user is represented as `null`, never as an anonymized stub.
 */
export interface UserSummary {
  id: string;
  username: string;
  name: string | null;
  avatar_path: string | null;
}
