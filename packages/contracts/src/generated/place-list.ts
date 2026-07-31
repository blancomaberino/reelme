/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/place-list.json
 */
/**
 * A saved collection in index form (T-062) — GET /api/v1/me/lists. Metadata + item count only; the places themselves are in place-list-detail.json.
 */
export interface PlaceListSummary {
  id: string;
  name: string;
  slug: string;
  /**
   * Global share token — non-null once the list has been made public (T-063).
   */
  public_slug: string | null;
  is_public: boolean;
  items_count: number;
  /**
   * Present only when the index was queried with ?contains={placeId}.
   */
  contains?: boolean;
  /**
   * Present only when the owner was eager-loaded; null when their profile is private.
   */
  owner?: null | UserSummary;
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
