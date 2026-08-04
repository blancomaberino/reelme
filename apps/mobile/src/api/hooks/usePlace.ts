import { useQuery } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { PlaceDetail } from '../places';

export async function fetchPlace(slug: string): Promise<PlaceDetail> {
  const { data } = await api.get<{ data: PlaceDetail }>(`/places/${encodeURIComponent(slug)}`, {
    // `offers` rides along rather than being a second request: the section sits
    // above the fold on a screen people open to decide whether to go, and a
    // separate fetch would pop it in after they had already scrolled past.
    params: { include: 'sources,reviews,offers' },
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
 * A place opened right after sharing it has an EMPTY gallery — the business photo
 * gallery (T-099) is populated asynchronously by the EnrichPlace job a few
 * seconds after publish. So whenever the gallery is empty we poll for a short
 * bounded window (see {@link galleryPollInterval}) until it lands, then stop, so
 * the carousel appears without a manual refresh. A place that already has a
 * gallery — or has none after enrichment — never polls past the small cap.
 */
export function usePlace(slug: string) {
  return useQuery({
    queryKey: queryKeys.place(slug),
    queryFn: () => fetchPlace(slug),
    staleTime: 60_000,
    enabled: slug.length > 0,
    refetchInterval: (query) => galleryPollInterval(query.state.data, query.state.dataUpdateCount),
  });
}
