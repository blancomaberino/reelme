import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { PlaceListDetail, PlaceListSummary } from '../lists';

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
