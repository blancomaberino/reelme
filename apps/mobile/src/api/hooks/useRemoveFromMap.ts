import { type InfiniteData, useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { Paginated, PlaceSummary } from '../places';

type Data = InfiniteData<Paginated<PlaceSummary>>;

/** Drop a place from every cached my-places page (optimistic remove). */
function removePlace(data: Data | undefined, placeId: string): Data | undefined {
  // setQueriesData spans the whole ['me','places'] prefix, which also covers the
  // non-paginated tag facet (['me','places','tags']) — leave any non-page-shaped
  // entry untouched so we only rewrite the infinite list pages.
  if (!data || !Array.isArray(data.pages)) return data;
  return { ...data, pages: data.pages.map((p) => ({ ...p, data: p.data.filter((row) => row.id !== placeId) })) };
}

/** How to remove a place from the aggregate collection (T-073). */
export type RemoveMode = 'hide' | 'full';

/**
 * Remove a place from my collection (T-071/T-073). `hide` (default) soft-hides
 * the pin — it stays in any lists; `full` permanently deletes my share(s) to it
 * and un-saves it everywhere. Optimistically strips it from the my-places cache
 * and invalidates the map (+ lists, since `full` un-saves) so its pin drops;
 * rolls back on error.
 */
export function useRemoveFromMap() {
  const qc = useQueryClient();
  const key = queryKeys.myPlacesAll();

  return useMutation({
    mutationFn: ({ place, mode = 'hide' }: { place: PlaceSummary; mode?: RemoveMode }) =>
      api.delete(`/me/places/${encodeURIComponent(place.id)}`, { params: { mode } }),
    onMutate: async ({ place }) => {
      await qc.cancelQueries({ queryKey: key });
      const prev = qc.getQueriesData<Data>({ queryKey: key });
      qc.setQueriesData<Data>({ queryKey: key }, (data) => removePlace(data, place.id));
      return { prev };
    },
    onError: (_err, _vars, ctx) => {
      ctx?.prev?.forEach(([k, data]) => qc.setQueryData(k, data));
    },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: key });
      qc.invalidateQueries({ queryKey: queryKeys.mapAll() });
      // `full` un-saves from lists, so refresh list detail + index too.
      qc.invalidateQueries({ queryKey: queryKeys.lists() });
    },
  });
}
