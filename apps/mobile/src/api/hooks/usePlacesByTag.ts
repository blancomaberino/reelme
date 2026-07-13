import { useInfiniteQuery } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { Paginated, PlaceSummary } from '../places';

async function fetchPage(slug: string, cursor: string | null): Promise<Paginated<PlaceSummary>> {
  const params: Record<string, string | number | string[]> = { 'tags[]': [slug], limit: 20 };
  if (cursor) params.cursor = cursor;
  const { data } = await api.get<Paginated<PlaceSummary>>('/places', { params });
  return data;
}

/** Places carrying a given tag slug (T-034 search → tag result → GET /places?tags[]=). */
export function usePlacesByTag(slug: string) {
  return useInfiniteQuery({
    queryKey: queryKeys.placesByTag(slug),
    queryFn: ({ pageParam }) => fetchPage(slug, pageParam),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta.pagination.next_cursor,
    enabled: slug.length > 0,
    staleTime: 60_000,
  });
}
