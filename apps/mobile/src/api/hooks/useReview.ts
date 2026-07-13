import { useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { AppReview } from '../places';

/**
 * Write / update / delete the viewer's single native review for a place (T-059).
 * `save` upserts (PUT), `remove` deletes; both invalidate the place detail so
 * the reviews list + `rating.app` aggregate refresh.
 */
export function useReview(slug: string) {
  const qc = useQueryClient();
  const invalidate = () => qc.invalidateQueries({ queryKey: queryKeys.place(slug) });

  const save = useMutation({
    mutationFn: async (input: { placeId: string; rating: number; body: string | null }): Promise<AppReview> => {
      const { data } = await api.put<{ data: AppReview }>(
        `/places/${encodeURIComponent(input.placeId)}/reviews`,
        { rating: input.rating, body: input.body },
      );
      return data.data;
    },
    onSuccess: invalidate,
  });

  const remove = useMutation({
    mutationFn: (placeId: string) => api.delete(`/places/${encodeURIComponent(placeId)}/reviews`),
    onSuccess: invalidate,
  });

  return { save, remove };
}
