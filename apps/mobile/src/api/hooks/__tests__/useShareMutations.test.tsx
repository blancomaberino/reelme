import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import { api } from '../../client';
import { queryKeys } from '../../keys';
import { useDiscardShare } from '../useDiscardShare';
import { useUpdateShare } from '../useUpdateShare';
import { reviewShare } from '@/test/share-fixtures';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

const invalidatedKeys = (spy: jest.SpyInstance) =>
  spy.mock.calls.map((call) => JSON.stringify((call[0] as { queryKey: unknown }).queryKey));

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('useUpdateShare seeds the share and invalidates the list, map and my-places', async () => {
  const invalidate = jest.spyOn(qc, 'invalidateQueries');
  const setData = jest.spyOn(qc, 'setQueryData');
  mock.onPatch('/shares/1').reply(200, { data: reviewShare({ status: 'analyzing', failure: null }) });

  const { result } = renderHook(() => useUpdateShare('1'), { wrapper });
  result.current.mutate({ action: 'publish' });
  await waitFor(() => expect(result.current.isSuccess).toBe(true));

  // optimistic seed of the share detail so polling resumes with the new state
  // (asserted via the spy — with no mounted observer + gcTime:0 it's GC'd instantly)
  expect(setData).toHaveBeenCalledWith(queryKeys.share('1'), expect.objectContaining({ status: 'analyzing' }));
  // the recent-shares list (stopped polling on the terminal `review`) must refresh
  const keys = invalidatedKeys(invalidate);
  expect(keys).toContain(JSON.stringify(queryKeys.sharesListAll()));
  expect(keys).toContain(JSON.stringify(queryKeys.mapAll()));
  expect(keys).toContain(JSON.stringify(queryKeys.myPlacesAll()));
});

it('useDiscardShare removes the share detail and invalidates the list', async () => {
  qc.setQueryData(queryKeys.share('1'), reviewShare());
  const invalidate = jest.spyOn(qc, 'invalidateQueries');
  mock.onDelete('/shares/1').reply(200, {});

  const { result } = renderHook(() => useDiscardShare('1'), { wrapper });
  result.current.mutate();
  await waitFor(() => expect(result.current.isSuccess).toBe(true));

  expect(qc.getQueryData(queryKeys.share('1'))).toBeUndefined();
  expect(invalidatedKeys(invalidate)).toContain(JSON.stringify(queryKeys.sharesListAll()));
});
