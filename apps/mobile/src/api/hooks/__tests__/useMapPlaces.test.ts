import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import { createElement, type ReactNode } from 'react';

import { api } from '@/api/client';
import type { Region } from '@/lib/geo';

import { useMapPlaces } from '../useMapPlaces';

let mock: AxiosMockAdapter;
let qc: QueryClient;

const REGION: Region = { latitude: -34.9, longitude: -56.16, latitudeDelta: 0.15, longitudeDelta: 0.15 };

const RESPONSE = {
  data: { pins: [], clusters: [] },
  meta: { zoom: 11, total_in_bbox: 0, clustered: true },
};

function wrapper({ children }: { children: ReactNode }) {
  return createElement(QueryClientProvider, { client: qc }, children);
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mock.onGet('/map/places').reply(200, RESPONSE);
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

it('requests /map/places with bbox + integer zoom', async () => {
  const { result } = renderHook(() => useMapPlaces(REGION, {}), { wrapper });
  await waitFor(() => expect(result.current.isSuccess).toBe(true));

  const req = mock.history.get[0];
  expect(req.params.bbox).toMatch(/^-?\d+\.\d+,-?\d+\.\d+,-?\d+\.\d+,-?\d+\.\d+$/);
  expect(Number.isInteger(req.params.zoom)).toBe(true);
});

it('reuses the cache for a tiny pan (same quantized key → no new request)', async () => {
  const { result, rerender } = renderHook<ReturnType<typeof useMapPlaces>, { r: Region }>(
    ({ r }) => useMapPlaces(r, {}),
    { wrapper, initialProps: { r: REGION } },
  );
  await waitFor(() => expect(result.current.isSuccess).toBe(true));
  const first = mock.history.get.length;

  // Nudge the center by a hair — within one grid cell.
  rerender({ r: { ...REGION, latitude: REGION.latitude + 0.00005 } });
  await waitFor(() => expect(result.current.isSuccess).toBe(true));
  expect(mock.history.get.length).toBe(first);
});

it('refetches when a filter changes', async () => {
  const { result, rerender } = renderHook<ReturnType<typeof useMapPlaces>, { f: { price_range?: number } }>(
    ({ f }) => useMapPlaces(REGION, f),
    { wrapper, initialProps: { f: {} } },
  );
  await waitFor(() => expect(result.current.isSuccess).toBe(true));
  const first = mock.history.get.length;

  rerender({ f: { price_range: 3 } });
  await waitFor(() => expect(mock.history.get.length).toBe(first + 1));
  expect(mock.history.get[first].params.price_range).toBe(3);
});

it('does not fetch when region is null', async () => {
  const { result } = renderHook(() => useMapPlaces(null, {}), { wrapper });
  // Give react-query a tick; it must stay disabled (no request).
  await waitFor(() => expect(result.current.fetchStatus).toBe('idle'));
  expect(mock.history.get.length).toBe(0);
});
