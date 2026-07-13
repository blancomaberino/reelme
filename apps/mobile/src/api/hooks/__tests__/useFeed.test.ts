import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import { createElement, type ReactNode } from 'react';

import { api } from '@/api/client';

import { useFeed } from '../useFeed';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function feedItem(id: string) {
  return {
    id,
    published_at: '2026-07-12T20:00:00Z',
    sharer: { id: '6', username: 'foodie', name: 'Foodie', avatar_path: null },
    source_post: { platform: 'instagram', url: 'https://ig.com/x', caption: 'c', thumbnail_url: null },
    influencer: null,
    place: {
      id,
      name: `Place ${id}`,
      slug: `place-${id}`,
      status: 'pending',
      lat: 0,
      lng: 0,
      category: null,
      price_range: null,
      city: 'Montevideo',
      country_code: 'UY',
      source_count: 1,
      rating: { google: { value: null, count: 0 } },
      distance_m: null,
      created_at: null,
    },
  };
}

function wrapper({ children }: { children: ReactNode }) {
  return createElement(QueryClientProvider, { client: qc }, children);
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

it('walks the cursor: appends page 2 without dupes', async () => {
  mock
    .onGet('/feed', { params: { scope: 'global', limit: 20 } })
    .reply(200, { data: [feedItem('1'), feedItem('2')], meta: { pagination: { next_cursor: 'CUR', prev_cursor: null, limit: 20 } } });
  mock
    .onGet('/feed', { params: { scope: 'global', limit: 20, cursor: 'CUR' } })
    .reply(200, { data: [feedItem('3')], meta: { pagination: { next_cursor: null, prev_cursor: null, limit: 20 } } });

  const { result } = renderHook(() => useFeed('global'), { wrapper });
  await waitFor(() => expect(result.current.isSuccess).toBe(true));
  expect(result.current.data?.pages[0].data).toHaveLength(2);
  expect(result.current.hasNextPage).toBe(true);

  await result.current.fetchNextPage();
  await waitFor(() => expect(result.current.data?.pages).toHaveLength(2));

  const ids = result.current.data?.pages.flatMap((p) => p.data.map((i) => i.id));
  expect(ids).toEqual(['1', '2', '3']);
  expect(result.current.hasNextPage).toBe(false);
});
