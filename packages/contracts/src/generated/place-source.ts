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
