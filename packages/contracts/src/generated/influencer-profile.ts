/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/influencer-profile.json
 */
/**
 * GET /api/v1/influencers/{id} data payload (T-036). `claimed_by_user_id` is never serialized raw — only a boolean plus the public claimer username.
 */
export interface InfluencerProfile {
  id: string;
  platform: string;
  handle: string;
  display_name: string | null;
  avatar_url: string | null;
  claimed: boolean;
  claimed_by: string | null;
  follower_count: number;
  counters: {
    promoted_places: number;
  };
}
