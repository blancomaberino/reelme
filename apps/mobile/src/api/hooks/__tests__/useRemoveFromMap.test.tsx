import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import { api } from '@/api/client';
import { useRemoveFromMap } from '@/api/hooks/useRemoveFromMap';
import { queryKeys } from '@/api/keys';
import type { PlaceSummary, TagSummary } from '@/api/places';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

function place(id: string): PlaceSummary {
  return {
    id,
    name: `Place ${id}`,
    slug: `place-${id}`,
    status: 'active',
    lat: 0,
    lng: 0,
    category: null,
    price_range: null,
    city: null,
    country_code: 'UY',
    source_count: 0,
    rating: { google: { value: null, count: 0 } },
    distance_m: null,
    created_at: null,
  };
}

beforeEach(() => {
  // gcTime Infinity: the seeded caches have no observer, so a 0 gcTime would
  // garbage-collect them before the mutation/assertions run.
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } } });
  mock = new AxiosMockAdapter(api);
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('optimistically removes the place from the list but leaves the tag facet cache intact', async () => {
  // Two caches share the ['me','places'] prefix the mutation's setQueriesData spans:
  // the paginated list, and the NON-paginated tag facet (a plain TagSummary[]).
  qc.setQueryData(queryKeys.myPlaces({ sort: 'recent' }), {
    pages: [{ data: [place('1'), place('2')], meta: { pagination: { next_cursor: null, prev_cursor: null, limit: 20 } } }],
    pageParams: [null],
  });
  const facet: TagSummary[] = [{ id: 't1', kind: 'cuisine', name: 'Ramen', slug: 'ramen' }];
  qc.setQueryData(queryKeys.myPlacesTags(), facet);
  mock.onDelete('/me/places/1').reply(204);

  const { result } = renderHook(() => useRemoveFromMap(), { wrapper });
  result.current.mutate({ place: place('1'), mode: 'full' });

  // The InfiniteData list is rewritten (place 1 stripped)…
  await waitFor(() => {
    const list = qc.getQueryData<{ pages: { data: PlaceSummary[] }[] }>(queryKeys.myPlaces({ sort: 'recent' }));
    expect(list?.pages[0].data.map((r) => r.id)).toEqual(['2']);
  });
  // …while the facet array is returned untouched — the shape guard stops the
  // InfiniteData updater from throwing on (and corrupting) `data.pages`.
  expect(qc.getQueryData(queryKeys.myPlacesTags())).toEqual(facet);
});
