import { useInfiniteQuery } from '@tanstack/react-query';

import { api } from '../client';
import { type MyPlacesFilters, queryKeys } from '../keys';
import type { Paginated, PlaceSummary } from '../places';

async function fetchMyPlacesPage(filters: MyPlacesFilters, cursor: string | null): Promise<Paginated<PlaceSummary>> {
  const params: Record<string, string | number | string[]> = { limit: 20 };
  if (filters.country) params.country = filters.country;
  if (filters.type) params.type = filters.type;
  if (filters.tags && filters.tags.length > 0) params['tags[]'] = filters.tags;
  if (filters.q) params.q = filters.q;
  if (filters.sort) params.sort = filters.sort;
  if (cursor) params.cursor = cursor;

  const { data } = await api.get<Paginated<PlaceSummary>>('/me/places', { params });
  return data;
}

/**
 * The personal "my places" list (T-071) — the list view of my map, replacing
 * the removed global feed. Places I shared (not soft-hidden) ∪ places I saved,
 * narrowed by the country/type/tag facets. Keyed by the filters so changing a
 * facet starts fresh pagination (a cursor is valid only for one filter+sort).
 */
export function useMyPlaces(filters: MyPlacesFilters) {
  return useInfiniteQuery({
    queryKey: queryKeys.myPlaces(filters),
    queryFn: ({ pageParam }) => fetchMyPlacesPage(filters, pageParam),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta.pagination.next_cursor,
    staleTime: 30_000,
  });
}
