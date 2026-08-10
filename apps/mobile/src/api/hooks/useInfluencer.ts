import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import type { InfluencerClaim, InfluencerResponse } from '../influencers';
import type { PlaceSummary } from '../places';
import { queryKeys } from '../keys';

/** An influencer's public profile + the viewer's follow state (T-036/T-039). */
export function useInfluencer(id: string | null) {
  return useQuery({
    queryKey: queryKeys.influencer(id ?? ''),
    queryFn: async () => {
      const { data } = await api.get<InfluencerResponse>(`/influencers/${encodeURIComponent(id as string)}`);
      return { profile: data.data, viewer: data.meta.viewer };
    },
    enabled: !!id,
    staleTime: 30_000,
  });
}

/**
 * Follow / unfollow an influencer. Mirrors {@see useFollow} for users, but the
 * follow API is keyed by `followable_type` so the two cannot share a hook
 * without the caller passing the type — and getting that wrong follows the
 * wrong entity silently.
 */
export function useFollowInfluencer() {
  const qc = useQueryClient();
  const invalidate = (id: string) => qc.invalidateQueries({ queryKey: queryKeys.influencer(id) });

  const follow = useMutation({
    mutationFn: (v: { id: string }) =>
      api.post('/follows', { followable_type: 'influencer', followable_id: Number(v.id) }),
    onSuccess: (_r, v) => invalidate(v.id),
  });

  const unfollow = useMutation({
    mutationFn: (v: { id: string; followId: string }) =>
      api.delete(`/follows/${encodeURIComponent(v.followId)}`),
    onSuccess: (_r, v) => invalidate(v.id),
  });

  return { follow, unfollow };
}

/**
 * The viewer's own claim on this identity, if any (T-038). 404s for a viewer
 * who has never claimed, which is the common case — so a miss resolves to null
 * rather than surfacing as an error state on the profile.
 */
export function useInfluencerClaim(id: string | null, opts?: { enabled?: boolean }) {
  return useQuery({
    queryKey: queryKeys.influencerClaim(id ?? ''),
    queryFn: async (): Promise<InfluencerClaim | null> => {
      try {
        const { data } = await api.get<{ data: InfluencerClaim | null }>(
          `/influencers/${encodeURIComponent(id as string)}/claim`,
        );
        return data.data;
      } catch {
        // No claim yet — not an error worth a retry banner.
        return null;
      }
    },
    enabled: !!id && (opts?.enabled ?? true),
    // NOT zero. The claim only changes through this screen's own mutations,
    // which write the authoritative result via setQueryData — a zero staleTime
    // makes that write instantly stale, so the refetch it triggers races and
    // can clobber a freshly-issued one-time code with whatever the GET says.
    staleTime: 30_000,
  });
}

export type ClaimMethod = 'oauth' | 'bio_code';

/**
 * Start or advance a claim (T-038). `action: 'verify'` re-checks a pending
 * bio-code claim; without it, the call issues the code (or completes instantly
 * on the OAuth path when a linked platform account already matches).
 */
export function useClaimInfluencer(id: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (v: { method: ClaimMethod; verify?: boolean }): Promise<InfluencerClaim> => {
      const { data } = await api.post<{ data: InfluencerClaim }>(
        `/influencers/${encodeURIComponent(id)}/claim`,
        { method: v.method, ...(v.verify ? { action: 'verify' } : {}) },
      );
      return data.data;
    },
    onSuccess: (claim) => {
      qc.setQueryData(queryKeys.influencerClaim(id), claim);
      // A verified claim flips `claimed` on the profile and `is_influencer` on
      // the viewer, so both have to be refetched, not just the claim.
      //
      // `exact` is load-bearing: the claim key is NESTED under the profile key
      // (['influencer', id, 'claim']), so a prefix invalidation would also
      // invalidate the claim we just wrote above — refetching it and, for a
      // caller whose GET still 404s, throwing away a one-time code.
      void qc.invalidateQueries({ queryKey: queryKeys.influencer(id), exact: true });
      void qc.invalidateQueries({ queryKey: queryKeys.me });
    },
  });
}

/**
 * Every place this influencer put on the map (T-036).
 *
 * `/influencers/{id}/map` is a VIEWPORT endpoint — same pin/cluster shape as
 * the main map — so it needs a bbox. One influencer's places are few enough to
 * ask for the whole world in one go and fit the result, which avoids dragging
 * the full pan/debounce/cluster machinery onto a read-only screen. The server
 * caps the response at 300 pins, which is far above any real influencer.
 */
/**
 * An influencer's places as a LIST — the direct sibling of `useUserPlaces`,
 * and what both the profile and its map screen read.
 *
 * REPLACES a `useInfluencerMap` that called the viewport endpoint with
 * `minLng/minLat/maxLng/maxLat` as separate params and a whole-globe extent.
 * The API takes `bbox` as ONE comma-joined string and rejects a globe-spanning
 * span outright, so every call 422'd — and the screen, which could not tell a
 * failed request from an empty one, rendered "no places from this creator" for
 * every influencer that had them. A list has no viewport to get wrong.
 */
export function useInfluencerPlaces(id: string | null) {
  return useQuery({
    queryKey: [...queryKeys.influencer(id ?? ''), 'places'] as const,
    queryFn: async (): Promise<PlaceSummary[]> => {
      const { data } = await api.get<{ data: PlaceSummary[] }>(
        `/influencers/${encodeURIComponent(id as string)}/places`,
        { params: { limit: 50 } },
      );
      return data.data;
    },
    enabled: !!id,
    staleTime: 30_000,
  });
}
