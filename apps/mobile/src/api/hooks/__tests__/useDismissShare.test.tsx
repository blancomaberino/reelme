import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import { api } from '@/api/client';
import { queryKeys } from '@/api/keys';
import type { FeedItem, Paginated } from '@/api/places';

import { useDismissShare } from '../useDismissShare';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function page(ids: string[]): Paginated<FeedItem> {
  return {
    data: ids.map((id) => ({ id }) as FeedItem),
    meta: { pagination: { next_cursor: null, prev_cursor: null, limit: 20 } },
  };
}

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  // gcTime: Infinity — the feed query is seeded directly (no mounted useFeed
  // observer), so a 0 gcTime would collect it mid-mutation and defeat rollback.
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity } } });
  mock = new AxiosMockAdapter(api);
  qc.setQueryData(queryKeys.feed('global'), { pageParams: [null], pages: [page(['1', '2'])] });
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('optimistically removes the share from every feed page', async () => {
  mock.onPost('/feed/hidden').reply(201);
  const { result } = renderHook(() => useDismissShare('global'), { wrapper });

  await act(async () => {
    await result.current.hide.mutateAsync('1');
  });

  const data = qc.getQueryData(queryKeys.feed('global')) as { pages: Paginated<FeedItem>[] };
  expect(data.pages[0].data.map((i) => i.id)).toEqual(['2']);
});

it('rolls back the removal when the request fails', async () => {
  mock.onPost('/feed/hidden').reply(500);
  const { result } = renderHook(() => useDismissShare('global'), { wrapper });

  await act(async () => {
    await result.current.hide.mutateAsync('1').catch(() => {});
  });

  const data = qc.getQueryData(queryKeys.feed('global')) as { pages: Paginated<FeedItem>[] };
  expect(data.pages[0].data.map((i) => i.id)).toEqual(['1', '2']);
});

it('undo deletes the dismissal and invalidates the feed', async () => {
  mock.onDelete('/feed/hidden/1').reply(204);
  const spy = jest.spyOn(qc, 'invalidateQueries');
  const { result } = renderHook(() => useDismissShare('global'), { wrapper });

  await act(async () => {
    await result.current.undo.mutateAsync('1');
  });
  await waitFor(() =>
    expect(spy).toHaveBeenCalledWith({ queryKey: queryKeys.feed('global') }),
  );
});
