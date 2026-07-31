/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/influencer-summary.json
 */
/**
 * Compact influencer attribution block (03 §2.6) — the shape of InfluencerSummaryResource, embedded in feed rows and place-source rows. Never the full influencer profile (see influencer-profile.json).
 */
export interface InfluencerSummary {
  id: string;
  platform: string;
  handle: string;
  display_name: string | null;
  avatar_url: string | null;
}
