/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/influencer-claim.json
 */
/**
 * GET/POST /api/v1/influencers/{id}/claim data payload (T-038). The caller's own claim state: status, verification method, and the one-time bio code (their own) while pending.
 */
export interface InfluencerClaim {
  id: string;
  influencer_id: string;
  status: 'pending' | 'verified' | 'rejected';
  method: 'oauth' | 'bio_code';
  token: string | null;
  expires_at: string | null;
}
