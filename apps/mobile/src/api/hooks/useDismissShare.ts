import { type InfiniteData, useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { FeedItem, Paginated } from '../places';

import type { FeedScope } from './useFeed';

type FeedData = InfiniteData<Paginated<FeedItem>>;

/** Drop a share from every cached feed page (optimistic hide). */
function removeFromFeed(data: FeedData | undefined, shareId: string): FeedData | undefined {
  if (!data) return data;
  return {
    ...data,
    pages: data.pages.map((page) => ({ ...page, data: page.data.filter((i) => i.id !== shareId) })),
  };
}

/**
 * "Hide from my feed" (non-destructive). Optimistically removes the share from
 * the feed cache and POSTs the dismissal; rolls back on error. `undo` deletes
 * the dismissal and refetches so the share reappears in its correct position.
 * Only wired for authed viewers (guests can't dismiss).
 */
export function useDismissShare(scope: FeedScope) {
  const qc = useQueryClient();
  const key = queryKeys.feed(scope);

  const hide = useMutation({
    mutationFn: (shareId: string) => api.post('/feed/hidden', { share_id: Number(shareId) }),
    onMutate: async (shareId) => {
      await qc.cancelQueries({ queryKey: key });
      const prev = qc.getQueryData<FeedData>(key);
      qc.setQueryData<FeedData>(key, (data) => removeFromFeed(data, shareId));
      return { prev };
    },
    onError: (_err, _shareId, ctx) => {
      if (ctx?.prev) qc.setQueryData(key, ctx.prev);
    },
  });

  const undo = useMutation({
    mutationFn: (shareId: string) => api.delete(`/feed/hidden/${encodeURIComponent(shareId)}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: key }),
  });

  return { hide, undo };
}
