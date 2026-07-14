import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { PlaceListDetail, PlaceListSummary, PublicPlaceList } from '../lists';

/**
 * The viewer's place lists (T-062), newest-touched first. Pass `containsPlaceId`
 * to also get a `contains` flag per list (for the save-to-list picker).
 */
export function useLists(containsPlaceId?: string) {
  return useQuery({
    queryKey: containsPlaceId ? ([...queryKeys.lists(), 'contains', containsPlaceId] as const) : queryKeys.lists(),
    queryFn: async () => {
      const { data } = await api.get<{ data: PlaceListSummary[] }>('/me/lists', {
        params: containsPlaceId ? { contains: Number(containsPlaceId) } : undefined,
      });
      return data.data;
    },
    staleTime: containsPlaceId ? 0 : 30_000,
  });
}

/** A single list with its places. */
export function useList(id: string | null) {
  return useQuery({
    queryKey: queryKeys.list(id ?? ''),
    queryFn: async () => {
      const { data } = await api.get<{ data: PlaceListDetail }>(`/me/lists/${encodeURIComponent(id as string)}`);
      return data.data;
    },
    enabled: !!id,
  });
}

/**
 * A shared list read by its public_slug (T-063). Public — works for guests; the
 * API 404s a private/missing list, which surfaces here as a query error.
 */
export function usePublicList(slug: string | null) {
  return useQuery({
    queryKey: queryKeys.publicList(slug ?? ''),
    queryFn: async () => {
      const { data } = await api.get<{ data: PublicPlaceList }>(`/lists/${encodeURIComponent(slug as string)}`);
      return data.data;
    },
    enabled: !!slug,
    retry: false,
  });
}

/** Update a list's name and/or public flag (T-062/T-063). Returns the updated
 *  summary — including the minted `public_slug` when it is toggled public. */
export function useUpdateList() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (v: { id: string; name?: string; is_public?: boolean }): Promise<PlaceListSummary> => {
      const { data } = await api.patch<{ data: PlaceListSummary }>(
        `/me/lists/${encodeURIComponent(v.id)}`,
        { name: v.name, is_public: v.is_public },
      );
      return data.data;
    },
    onSuccess: (_r, v) => {
      void qc.invalidateQueries({ queryKey: queryKeys.list(v.id) });
      void qc.invalidateQueries({ queryKey: queryKeys.lists() });
    },
  });
}

/** Save a copy of a shared (public) list into the caller's own lists (T-063). */
export function useCopyList() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (publicSlug: string): Promise<PlaceListDetail> => {
      const { data } = await api.post<{ data: PlaceListDetail }>(
        `/me/lists/${encodeURIComponent(publicSlug)}/copy`,
      );
      return data.data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: queryKeys.lists() }),
  });
}

/** Create a new list. */
export function useCreateList() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (name: string): Promise<PlaceListSummary> => {
      const { data } = await api.post<{ data: PlaceListSummary }>('/me/lists', { name });
      return data.data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: queryKeys.lists() }),
  });
}

/** Delete a list. */
export function useDeleteList() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => api.delete(`/me/lists/${encodeURIComponent(id)}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: queryKeys.lists() }),
  });
}

/**
 * Add / remove a place across lists. Invalidates the affected list detail + the
 * index (item counts) so pickers and the lists screen stay in sync.
 */
export function useListMembership() {
  const qc = useQueryClient();
  const invalidate = (listId: string) => {
    void qc.invalidateQueries({ queryKey: queryKeys.list(listId) });
    void qc.invalidateQueries({ queryKey: queryKeys.lists() });
  };

  const add = useMutation({
    mutationFn: (v: { listId: string; placeId: string }) =>
      api.post(`/me/lists/${encodeURIComponent(v.listId)}/places/${encodeURIComponent(v.placeId)}`),
    onSuccess: (_r, v) => invalidate(v.listId),
  });

  const remove = useMutation({
    mutationFn: (v: { listId: string; placeId: string }) =>
      api.delete(`/me/lists/${encodeURIComponent(v.listId)}/places/${encodeURIComponent(v.placeId)}`),
    onSuccess: (_r, v) => invalidate(v.listId),
  });

  return { add, remove };
}
