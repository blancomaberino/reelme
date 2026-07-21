import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import { api } from '@/api/client';

import { useCreateShare } from '../useCreateShare';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity } } });
  mock = new AxiosMockAdapter(api);
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('POSTs the pasted link with shared_via and seeds the share cache', async () => {
  let sent: Record<string, unknown> = {};
  mock.onPost('/shares').reply((cfg) => {
    sent = JSON.parse(cfg.data);
    return [201, { data: { id: '7', status: 'pending' } }];
  });

  const { result } = renderHook(() => useCreateShare(), { wrapper });
  let created: { id: string; status: string; idempotentReplay: boolean } | undefined;
  await act(async () => {
    created = await result.current.mutateAsync({ url: 'https://ig.com/reel/x', caption: '' });
  });

  expect(sent).toEqual({ url: 'https://ig.com/reel/x', shared_via: 'paste_url' });
  // Empty caption is dropped (undefined), not sent as "".
  expect(sent).not.toHaveProperty('caption');
  // Returns only the ack (id + status + replay flag) — the stripped 202 body is
  // NOT seeded into the detail cache (useShareStatus fetches the full state).
  // No `meta` → idempotentReplay defaults false.
  expect(created).toEqual({ id: '7', status: 'pending', idempotentReplay: false });
});

it('flags an idempotent replay from meta so the screen can note "already added"', async () => {
  mock.onPost('/shares').reply(202, { data: { id: '7', status: 'published' }, meta: { idempotent_replay: true } });

  const { result } = renderHook(() => useCreateShare(), { wrapper });
  let created: { idempotentReplay: boolean } | undefined;
  await act(async () => {
    created = await result.current.mutateAsync({ url: 'https://ig.com/reel/x' });
  });

  expect(created).toMatchObject({ id: '7', status: 'published', idempotentReplay: true });
});

it('forwards an explicit sharedVia (share sheet) over the default', async () => {
  let sent: Record<string, unknown> = {};
  mock.onPost('/shares').reply((cfg) => {
    sent = JSON.parse(cfg.data);
    return [202, { data: { id: '9', status: 'pending' } }];
  });

  const { result } = renderHook(() => useCreateShare(), { wrapper });
  await act(async () => {
    await result.current.mutateAsync({ url: 'https://ig.com/reel/x', sharedVia: 'share_sheet' });
  });

  expect(sent).toMatchObject({ url: 'https://ig.com/reel/x', shared_via: 'share_sheet' });
});

it('sends a caption when no url is given', async () => {
  let sent: Record<string, unknown> = {};
  mock.onPost('/shares').reply((cfg) => {
    sent = JSON.parse(cfg.data);
    return [201, { data: { id: '8', status: 'pending' } }];
  });

  const { result } = renderHook(() => useCreateShare(), { wrapper });
  await act(async () => {
    await result.current.mutateAsync({ url: '', caption: 'Best tacos in Lisbon' });
  });

  expect(sent).toEqual({ caption: 'Best tacos in Lisbon', shared_via: 'paste_url' });
  expect(sent).not.toHaveProperty('url');
});
