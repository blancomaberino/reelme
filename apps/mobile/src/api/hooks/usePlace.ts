import { useQuery } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { PlaceDetail } from '../places';

export async function fetchPlace(slug: string): Promise<PlaceDetail> {
  const { data } = await api.get<{ data: PlaceDetail }>(`/places/${encodeURIComponent(slug)}`, {
    params: { include: 'sources' },
  });
  return data.data;
}

/**
 * Place detail (T-033). `staleTime` is modest (60s, not the map's 120s) so a
 * revisit refetches sooner; the real guard against expired presigned R2
 * thumbnail URLs is the Thumbnail's onError → placeholder fallback (staleTime
 * alone can't guarantee a fresh URL for an already-mounted screen).
 */
export function usePlace(slug: string) {
  return useQuery({
    queryKey: queryKeys.place(slug),
    queryFn: () => fetchPlace(slug),
    staleTime: 60_000,
    enabled: slug.length > 0,
  });
}
