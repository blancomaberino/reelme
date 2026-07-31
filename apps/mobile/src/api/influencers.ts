import type { InfluencerClaim, InfluencerProfile } from '@reelmap/contracts';

/**
 * Influencer profile + claim shapes (T-039), derived from the JSON Schemas in
 * `@reelmap/contracts` so a field rename fails typecheck rather than surfacing
 * as an undefined at runtime (T-094).
 */
export type { InfluencerClaim, InfluencerProfile };

/** Viewer-relative follow state (meta.viewer on GET /influencers/{id}). */
export type InfluencerViewer = { following: boolean; follow_id: string | null };

export type InfluencerResponse = {
  data: InfluencerProfile;
  meta: { viewer: InfluencerViewer };
};

/** One selectable analysis model (GET /analysis/models). */
export type AnalysisModel = {
  id: string;
  label: string;
  provider: string;
  available: boolean;
};
