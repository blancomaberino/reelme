import { onlineManager, QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import MyPlacesScreen from '../places';
import { api } from '@/api/client';
import { queryKeys } from '@/api/keys';
import type { PlaceSummary } from '@/api/places';
import { useSessionStore } from '@/stores/session';

/**
 * "Where was that restaurant I saved?" has to answer on a subway platform
 * (T-103). These cover the three answers the list can give when it shows
 * nothing — offline, failed, genuinely empty — which the user must be able to
 * tell apart, plus the cache-served cold start and the reconnect.
 */

let mock: AxiosMockAdapter;
let qc: QueryClient;

function place(id: string, over: Partial<PlaceSummary> = {}): PlaceSummary {
  return {
    id,
    name: `Place ${id}`,
    slug: `place-${id}`,
    status: 'active',
    lat: 0,
    lng: 0,
    category: 'ramen',
    price_range: 2,
    city: 'Montevideo',
    country_code: 'UY',
    thumbnail_url: null,
    mine: { share_id: id, saved: false },
    source_count: 1,
    rating: { google: { value: null, count: 0 } },
    distance_m: null,
    created_at: null,
    ...over,
  };
}

function page(rows: PlaceSummary[], next: string | null = null) {
  return { data: rows, meta: { pagination: { next_cursor: next, prev_cursor: null, limit: 20 } } };
}

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

/** What the persisted cache rehydrates into for the default (unfiltered) view. */
function seedCache(rows: PlaceSummary[]) {
  qc.setQueryData(queryKeys.myPlaces({ sort: 'recent' }), {
    pages: [page(rows)],
    pageParams: [null],
  });
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mock.onGet('/me/places/facets').reply(200, { data: { countries: [], types: [] } });
  mock.onGet('/me/places/tags').reply(200, { data: [] });
  useSessionStore.setState({ user: null, status: 'authed' });
});

afterEach(() => {
  mock.restore();
  qc.clear();
  onlineManager.setOnline(true);
});

describe('My places, offline', () => {
  it('serves the rehydrated cache on a cold start with no network', async () => {
    seedCache([place('1', { name: 'Bar Tinta' })]);
    onlineManager.setOnline(false);

    render(<MyPlacesScreen />, { wrapper: Providers });

    expect(await screen.findByText('Bar Tinta')).toBeTruthy();
    // Not a spinner and not an empty map: the whole point of persisting.
    expect(screen.queryByTestId('my-places-offline')).toBeNull();
    // Nothing was even attempted — the query is parked, not retrying.
    expect(mock.history.get.filter((r) => r.url === '/me/places')).toHaveLength(0);
  });

  it('says "offline" — not "error", not "empty" — when there is nothing cached', async () => {
    onlineManager.setOnline(false);
    mock.onGet('/me/places').reply(200, page([]));

    render(<MyPlacesScreen />, { wrapper: Providers });

    expect(await screen.findByTestId('my-places-offline')).toBeTruthy();
    expect(screen.getByText('You’re offline')).toBeTruthy();
    // The three states are mutually exclusive: no retry affordance (there is
    // nothing to retry) and no "no places yet" (we don't know that).
    expect(screen.queryByTestId('my-places-error')).toBeNull();
    expect(screen.queryByText('No places yet')).toBeNull();
  });

  it('refetches by itself when the connection comes back', async () => {
    onlineManager.setOnline(false);
    mock.onGet('/me/places').reply(200, page([place('1', { name: 'Bar Tinta' })]));

    render(<MyPlacesScreen />, { wrapper: Providers });
    expect(await screen.findByTestId('my-places-offline')).toBeTruthy();

    await act(async () => {
      onlineManager.setOnline(true);
    });

    expect(await screen.findByText('Bar Tinta')).toBeTruthy();
    await waitFor(() => expect(mock.history.get.filter((r) => r.url === '/me/places')).toHaveLength(1));
  });

  it('still distinguishes a failed request from being offline', async () => {
    mock.onGet('/me/places').reply(500);

    render(<MyPlacesScreen />, { wrapper: Providers });

    expect(await screen.findByTestId('my-places-error')).toBeTruthy();
    expect(screen.queryByTestId('my-places-offline')).toBeNull();
  });

  it('still distinguishes a genuinely empty collection', async () => {
    mock.onGet('/me/places').reply(200, page([]));

    render(<MyPlacesScreen />, { wrapper: Providers });

    expect(await screen.findByText('No places yet')).toBeTruthy();
    expect(screen.queryByTestId('my-places-offline')).toBeNull();
    expect(screen.queryByTestId('my-places-error')).toBeNull();
  });
});
