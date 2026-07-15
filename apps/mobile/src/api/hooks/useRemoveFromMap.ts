import { type InfiniteData, useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import type { Paginated, PlaceSummary } from '../places';

type Page = Paginated<PlaceSummary>;
type Data = InfiniteData<Page>;

/** Drop a place from every cached my-places page (optimistic remove). */
function removePlace(data: Data | undefined, placeId: string): Data | undefined {
  if (!data) return data;
  return { ...data, pages: data.pages.map((p) => ({ ...p, data: p.data.filter((row) => row.id !== placeId) })) };
}

/**
 * "Remove from my map" (T-071): take a place out of my personal collection.
 * A place can be mine two ways, so removal undoes both — it soft-hides my live
 * share (`mine.share_id`, reusing the map-aware feed_dismissals) AND un-saves it
 * from every one of my lists (`mine.saved`). Optimistically strips it from the
 * my-places cache and invalidates the map so its pin drops; rolls back on error.
 */
export function useRemoveFromMap() {
  const qc = useQueryClient();
  const key = ['me', 'places'] as const;

  return useMutation({
    mutationFn: async (place: PlaceSummary) => {
      const mine = place.mine;
      if (mine?.share_id) {
        await api.post('/feed/hidden', { share_id: Number(mine.share_id) });
      }
      if (mine?.saved) {
        const { data } = await api.get<{ data: { id: string; contains?: boolean }[] }>('/me/lists', {
          params: { contains: Number(place.id) },
        });
        const containing = data.data.filter((l) => l.contains);
        await Promise.all(containing.map((l) => api.delete(`/me/lists/${l.id}/places/${place.id}`)));
      }
    },
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
      qc.invalidateQueries({ queryKey: ['places', 'map'] });
    },
  });
}
