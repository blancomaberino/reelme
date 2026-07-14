import type { FeedItem, InfluencerSummary, SharerSummary } from './places';

/** Public profile shape (GET /users/{username}). */
export type PublicProfile = {
  id: string;
  username: string;
  name: string | null;
  bio: string | null;
  avatar_path: string | null;
  is_influencer: boolean;
  counters: { published_shares: number; followers: number; following: number };
  created_at: string | null;
};

/** Viewer-relative follow state (meta.viewer on GET /users/{username}). */
export type ProfileViewer = { following: boolean; follow_id: string | null };

export type ProfileResponse = {
  data: { profile: PublicProfile; shares: FeedItem[] };
  meta: { viewer: ProfileViewer };
};

/** A row of GET /users/{username}/followers — the follower (null if now private). */
export type FollowerRow = { id: string; user: SharerSummary };

/** A row of GET /users/{username}/following — a user or influencer. */
export type FollowingRow = {
  id: string;
  followable_type: 'user' | 'influencer';
  followee: SharerSummary | InfluencerSummary | null;
};
