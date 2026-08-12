/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/user-profile.json
 */
/**
 * The `profile` object of GET /api/v1/users/{username} (T-036). Public fields only — never email, roles beyond is_influencer, or billing data.
 */
export interface UserProfile {
  id: string;
  username: string;
  name: string | null;
  bio: string | null;
  avatar_path: string | null;
  is_influencer: boolean;
  /**
   * ISO 3166-1 alpha-2, uppercase. Null when the user has not said where they are. Public by owner decision (T-110): coarse like `bio`, and what regional discovery needs.
   */
  country_code: string | null;
  /**
   * `country_code` rendered in the request locale (?locale= → Accept-Language). Server-resolved so no client ships a country dataset. Null exactly when `country_code` is.
   */
  country_name: string | null;
  counters: {
    published_shares: number;
    followers: number;
    following: number;
  };
  created_at: string | null;
}
