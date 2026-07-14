import { useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { MyPlaceTag } from '../places';

/**
 * Add / remove the viewer's private per-user tags for a place (T-064). Both
 * mutations invalidate the place detail so its owner-only `my_tags` list
 * refreshes. Tags are strictly personal — the server scopes every read/write to
 * the authed caller, so there is nothing here to reconcile across users.
 */
export function usePlaceTags(slug: string) {
  const qc = useQueryClient();
  const invalidate = () => qc.invalidateQueries({ queryKey: queryKeys.place(slug) });

  const add = useMutation({
    mutationFn: async (label: string): Promise<MyPlaceTag[]> => {
      const { data } = await api.post<{ data: MyPlaceTag[] }>(
        `/me/places/${encodeURIComponent(slug)}/tags`,
        { label },
      );
      return data.data;
    },
    onSuccess: invalidate,
  });

  const remove = useMutation({
    mutationFn: (tagId: string) =>
      api.delete(`/me/places/${encodeURIComponent(slug)}/tags/${encodeURIComponent(tagId)}`),
    onSuccess: invalidate,
  });

  return { add, remove };
}
