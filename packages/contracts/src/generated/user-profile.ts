/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/user-profile.json
 */
/**
 * The `profile` object of GET /api/v1/users/{username} (T-036). Public fields only — never email, roles beyond is_influencer, or billing data.
 */
export interface UserProfile {
  username: string;
  name: string | null;
  bio: string | null;
  avatar_path: string | null;
  is_influencer: boolean;
  counters: {
    published_shares: number;
    followers: number;
    following: number;
  };
  created_at: string | null;
}
