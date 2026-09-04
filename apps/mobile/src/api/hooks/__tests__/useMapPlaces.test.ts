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

// --- T-156: the viewer point, and the loop it has to survive ---

// Deliberately NOT on a 4-decimal rounding boundary: the drift case below is
// about the quantization step, not about which way `toFixed` breaks a tie.
const VIEWER = { latitude: -34.90112, longitude: -56.16452 };

it('sends near=lat,lng when the viewer shared a position, and omits it otherwise', async () => {
  const { result } = renderHook(() => useMapPlaces(REGION, {}, VIEWER), { wrapper });
  await waitFor(() => expect(result.current.isSuccess).toBe(true));
  // Quantized to ~11 m — asserted as the exact string, because a change in
  // precision changes the cache key and therefore how often the map refetches.
  expect(mock.history.get[0].params.near).toBe('-34.9011,-56.1645');

  const without = renderHook(() => useMapPlaces(REGION, {}, null), { wrapper });
  await waitFor(() => expect(without.result.current.isSuccess).toBe(true));
  // ABSENT, not empty: the API 422s a malformed `near` rather than ignoring it,
  // so an empty string would fail the whole request.
  expect('near' in mock.history.get[1].params).toBe(false);
});

it('does not refetch when the fix drifts within the quantization step', async () => {
  const { result, rerender } = renderHook<ReturnType<typeof useMapPlaces>, { v: typeof VIEWER }>(
    ({ v }) => useMapPlaces(REGION, {}, v),
    { wrapper, initialProps: { v: VIEWER } },
  );
  await waitFor(() => expect(result.current.isSuccess).toBe(true));
  const first = mock.history.get.length;

  // A phone sitting still on a table: the fix wanders a couple of metres.
  rerender({ v: { latitude: VIEWER.latitude + 0.000004, longitude: VIEWER.longitude } });
  await waitFor(() => expect(result.current.isSuccess).toBe(true));
  expect(mock.history.get.length).toBe(first);
});

it('refetches WITH the viewer point when the map is panned (the loop, not the first paint)', async () => {
  // The regression this guards: `near` left out of the query key. The first
  // paint looks perfect — distances render — and then every pan replays a
  // cached, distance-less page for the new cell, or (worse) serves the OLD
  // cell's distances under the new pins. A first-paint assertion cannot see it.
  const { result, rerender } = renderHook<ReturnType<typeof useMapPlaces>, { r: Region }>(
    ({ r }) => useMapPlaces(r, {}, VIEWER),
    { wrapper, initialProps: { r: REGION } },
  );
  await waitFor(() => expect(result.current.isSuccess).toBe(true));
  const first = mock.history.get.length;

  // A real pan — far enough to leave the quantization cell.
  rerender({ r: { ...REGION, latitude: REGION.latitude + 0.2 } });
  await waitFor(() => expect(mock.history.get.length).toBe(first + 1));

  const panned = mock.history.get[first];
  expect(panned.params.near).toBe('-34.9011,-56.1645');
  expect(panned.params.bbox).not.toBe(mock.history.get[0].params.bbox);
});

it('re-asks the same viewport when the fix arrives, instead of replaying the distance-less page', async () => {
  // The other half of the key: a map that opened before the GPS answered has a
  // cached page with no distances in it. If `near` were not part of the key,
  // that page would be served forever and the labels would never appear.
  const { result, rerender } = renderHook<
    ReturnType<typeof useMapPlaces>,
    { v: typeof VIEWER | null }
  >(({ v }) => useMapPlaces(REGION, {}, v), { wrapper, initialProps: { v: null } });
  await waitFor(() => expect(result.current.isSuccess).toBe(true));
  const first = mock.history.get.length;

  rerender({ v: VIEWER });
  await waitFor(() => expect(mock.history.get.length).toBe(first + 1));
  expect(mock.history.get[first].params.near).toBe('-34.9011,-56.1645');
});
