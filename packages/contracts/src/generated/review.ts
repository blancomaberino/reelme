/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/review.json
 */
/**
 * A native (in-app) review of a place (T-059) — the shape of ReviewResource. Served inside place detail under ?include=reviews, listed by GET /places/{place}/reviews, and returned on its own by POST/PUT /places/{place}/reviews. `author` is null when the reviewer is not a public profile: the identity is withheld, never anonymized into a stub.
 */
export interface Review {
  id: string;
  rating: number;
  body: string | null;
  /**
   * The reviewer, or null when their profile is not public.
   */
  author: null | UserSummary;
  /**
   * Whether THIS viewer wrote the review — drives the edit affordance. False for guests.
   */
  is_own: boolean;
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
