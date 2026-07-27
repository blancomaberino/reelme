import { useQuery } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { PlaceDetail } from '../places';

export async function fetchPlace(slug: string): Promise<PlaceDetail> {
  const { data } = await api.get<{ data: PlaceDetail }>(`/places/${encodeURIComponent(slug)}`, {
    params: { include: 'sources,reviews' },
  });
  return data.data;
}

/** Interval between gallery polls for a just-added place, and the fetch cap. */
const GALLERY_POLL_MS = 3_000;
const GALLERY_POLL_MAX_FETCHES = 7;

/**
 * ms until the next gallery poll, or false to stop. Stops as soon as the gallery
 * arrives, or after a bounded number of fetches so a place that legitimately
 * enriches to zero photos doesn't poll forever. Exported for testing.
 */
export function galleryPollInterval(data: PlaceDetail | undefined, dataUpdateCount: number): number | false {
  const hasGallery = (data?.gallery?.length ?? 0) > 0;
  return !hasGallery && dataUpdateCount < GALLERY_POLL_MAX_FETCHES ? GALLERY_POLL_MS : false;
}

/**
 * Place detail (T-033). `staleTime` is modest (60s, not the map's 120s) so a
 * revisit refetches sooner; the real guard against expired presigned R2
 * thumbnail URLs is the Thumbnail's onError → placeholder fallback (staleTime
 * alone can't guarantee a fresh URL for an already-mounted screen).
 *
 * @param opts.pollForGallery  For a JUST-ADDED place: the business photo gallery
 *   (T-099) is populated asynchronously by the EnrichPlace job a few seconds
 *   AFTER publish, so the place first opens with an empty gallery. When set, poll
 *   for a bounded window until the gallery lands (then stop), so the carousel
 *   appears without a manual refresh. Off for normal navigation.
 */
export function usePlace(slug: string, opts?: { pollForGallery?: boolean }) {
  return useQuery({
    queryKey: queryKeys.place(slug),
    queryFn: () => fetchPlace(slug),
    staleTime: 60_000,
    enabled: slug.length > 0,
    refetchInterval: opts?.pollForGallery
      ? (query) => galleryPollInterval(query.state.data, query.state.dataUpdateCount)
      : false,
  });
}
