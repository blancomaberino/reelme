import { type InfiniteData, useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { Paginated, PlaceSummary } from '../places';

type Data = InfiniteData<Paginated<PlaceSummary>>;

/** Drop a place from every cached my-places page (optimistic remove). */
function removePlace(data: Data | undefined, placeId: string): Data | undefined {
  if (!data) return data;
  return { ...data, pages: data.pages.map((p) => ({ ...p, data: p.data.filter((row) => row.id !== placeId) })) };
}

/**
 * "Remove from my map" (T-071): take a place out of my personal collection.
 * The server (DELETE /me/places/{id}) does the work transactionally — soft-hide
 * all my shares to the place AND un-save it from every list — so a place that's
 * mine two ways drops in one call. Optimistically strips it from the my-places
 * cache and invalidates the map so its pin drops; rolls back on error.
 */
export function useRemoveFromMap() {
  const qc = useQueryClient();
  const key = queryKeys.myPlacesAll();

  return useMutation({
    mutationFn: (place: PlaceSummary) => api.delete(`/me/places/${encodeURIComponent(place.id)}`),
    onMutate: async (place) => {
      await qc.cancelQueries({ queryKey: key });
      const prev = qc.getQueriesData<Data>({ queryKey: key });
      qc.setQueriesData<Data>({ queryKey: key }, (data) => removePlace(data, place.id));
      return { prev };
    },
    onError: (_err, _place, ctx) => {
      ctx?.prev?.forEach(([k, data]) => qc.setQueryData(k, data));
    },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: key });
      qc.invalidateQueries({ queryKey: queryKeys.mapAll() });
      // Removal also un-saves the place from my lists (server-side), so refresh
      // the list detail + index so they don't show stale membership/counts.
      qc.invalidateQueries({ queryKey: queryKeys.lists() });
    },
  });
}
