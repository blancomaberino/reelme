import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { SearchResponse } from '../places';

const TYPES = 'places,users,tags';

async function fetchSearch(q: string): Promise<SearchResponse['data']> {
  const { data } = await api.get<SearchResponse>('/search', { params: { q, types: TYPES } });
  return data.data;
}

/**
 * Multi-type search (T-034) over GET /search. Disabled below 2 chars (don't
 * hammer Meilisearch on the first keystroke); `keepPreviousData` so results
 * don't flash empty between debounced queries.
 */
export function useSearch(q: string) {
  const trimmed = q.trim();
  return useQuery({
    queryKey: queryKeys.search(trimmed, TYPES),
    queryFn: () => fetchSearch(trimmed),
    enabled: trimmed.length >= 2,
    placeholderData: keepPreviousData,
    staleTime: 60_000,
  });
}
