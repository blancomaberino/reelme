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
 * Place detail (T-033). `staleTime` is modest (60s, not the map's 120s) because
 * the embedded source thumbnails are presigned R2 URLs that expire — a long
 * cache would serve dead image links.
 */
export function usePlace(slug: string) {
  return useQuery({
    queryKey: queryKeys.place(slug),
    queryFn: () => fetchPlace(slug),
    staleTime: 60_000,
    enabled: slug.length > 0,
  });
}
