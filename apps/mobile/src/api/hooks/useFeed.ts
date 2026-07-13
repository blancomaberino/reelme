import { useInfiniteQuery } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { FeedItem, Paginated } from '../places';

export type FeedScope = 'global' | 'following';

async function fetchFeedPage(scope: FeedScope, cursor: string | null): Promise<Paginated<FeedItem>> {
  const params: Record<string, string | number> = { scope, limit: 20 };
  if (cursor) params.cursor = cursor;
  const { data } = await api.get<Paginated<FeedItem>>('/feed', { params });
  return data;
}

/**
 * Reverse-chron published-shares feed (T-034). Keyed by `[scope]` so switching
 * scope starts fresh pagination (a cursor is only valid for one query — reusing
 * it across scopes 422s). `global` is public; `following` is a stub until M3.
 */
export function useFeed(scope: FeedScope) {
  return useInfiniteQuery({
    queryKey: queryKeys.feed(scope),
    queryFn: ({ pageParam }) => fetchFeedPage(scope, pageParam),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta.pagination.next_cursor,
    staleTime: 30_000,
  });
}
