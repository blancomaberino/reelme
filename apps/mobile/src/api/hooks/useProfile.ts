import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { PlaceListSummary } from '../lists';
import type { PlaceSummary } from '../places';
import type { FollowerRow, FollowingRow, ProfileResponse } from '../profile';

/** Cap on a single followers/following page fetch (pagination is a follow-up). */
const LIST_LIMIT = 100;

/** Another user's public profile + their shares + the viewer's follow state. */
export function useProfile(username: string | null) {
  return useQuery({
    queryKey: queryKeys.profile(username ?? ''),
    queryFn: async () => {
      const { data } = await api.get<ProfileResponse>(`/users/${encodeURIComponent(username as string)}`);
      return { ...data.data, viewer: data.meta.viewer };
    },
    enabled: !!username,
    staleTime: 30_000,
  });
}

/** Follow / unfollow a user, refreshing the profile so the button + counts update. */
export function useFollow() {
  const qc = useQueryClient();
  const invalidate = (username: string) => qc.invalidateQueries({ queryKey: queryKeys.profile(username) });

  const follow = useMutation({
    mutationFn: (v: { username: string; userId: string }) =>
      api.post('/follows', { followable_type: 'user', followable_id: Number(v.userId) }),
    onSuccess: (_r, v) => invalidate(v.username),
  });

  const unfollow = useMutation({
    mutationFn: (v: { username: string; followId: string }) =>
      api.delete(`/follows/${encodeURIComponent(v.followId)}`),
    onSuccess: (_r, v) => invalidate(v.username),
  });

  return { follow, unfollow };
}

/**
 * A user's public places (T-071) — the list view of their map, shown on their
 * profile. First page only (limit 50, like the follow lists); it also powers
 * their per-user map screen. Never carries `mine` provenance (that's /me only).
 */
export function useUserPlaces(username: string | null) {
  return useQuery({
    queryKey: queryKeys.userPlaces(username ?? ''),
    queryFn: async () => {
      const { data } = await api.get<{ data: PlaceSummary[] }>(
        `/users/${encodeURIComponent(username as string)}/places`,
        { params: { limit: 50 } },
      );
      return data.data;
    },
    enabled: !!username,
    staleTime: 30_000,
  });
}

/** A user's PUBLIC lists (T-071/T-063) — shown on their profile. */
export function useUserLists(username: string | null) {
  return useQuery({
    queryKey: queryKeys.userLists(username ?? ''),
    queryFn: async () => {
      const { data } = await api.get<{ data: PlaceListSummary[] }>(
        `/users/${encodeURIComponent(username as string)}/lists`,
      );
      return data.data;
    },
    enabled: !!username,
    staleTime: 30_000,
  });
}

export function useFollowers(username: string | null) {
  return useQuery({
    queryKey: queryKeys.followers(username ?? ''),
    queryFn: async () => {
      const { data } = await api.get<{ data: FollowerRow[] }>(
        `/users/${encodeURIComponent(username as string)}/followers`,
        { params: { limit: LIST_LIMIT } },
      );
      return data.data;
    },
    enabled: !!username,
  });
}

export function useFollowing(username: string | null) {
  return useQuery({
    queryKey: queryKeys.following(username ?? ''),
    queryFn: async () => {
      const { data } = await api.get<{ data: FollowingRow[] }>(
        `/users/${encodeURIComponent(username as string)}/following`,
        { params: { limit: LIST_LIMIT } },
      );
      return data.data;
    },
    enabled: !!username,
  });
}
