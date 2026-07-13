import { useQuery } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { TagSummary } from '../places';

async function fetchPopularTags(): Promise<TagSummary[]> {
  const { data } = await api.get<{ data: TagSummary[] }>('/tags', { params: { popular: 1, limit: 15 } });
  return data.data;
}

/** Top tags for the map filter bar (T-032) / search suggestions. */
export function usePopularTags() {
  return useQuery({
    queryKey: queryKeys.tagsPopular(),
    queryFn: fetchPopularTags,
    staleTime: 10 * 60_000,
  });
}
