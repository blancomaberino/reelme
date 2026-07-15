import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';
import { Alert, type AlertButton } from 'react-native';

import MyPlacesScreen from '../places';
import { api } from '@/api/client';
import type { PlaceSummary } from '@/api/places';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../../../jest.setup';

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

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mock.onGet('/tags').reply(200, { data: [] });
  mockRouter.push.mockClear();
  // My places requires auth.
  useSessionStore.setState({ user: null, status: 'authed' });
});

afterEach(() => {
  mock.restore();
  qc.clear();
  useSessionStore.setState({ user: null, status: 'guest' });
});

it('lists my places with cuisine + city', async () => {
  mock.onGet('/me/places').reply(200, page([place('1'), place('2')]));

  render(<MyPlacesScreen />, { wrapper: Providers });

  expect(await screen.findByText('Place 1')).toBeOnTheScreen();
  expect(screen.getByText('Place 2')).toBeOnTheScreen();
  expect(screen.getAllByText('Montevideo').length).toBeGreaterThan(0);
});

it('taps a card through to the place detail', async () => {
  mock.onGet('/me/places').reply(200, page([place('1')]));

  render(<MyPlacesScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByLabelText('Place 1'));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/place/[slug]', params: { slug: 'place-1' } });
});

it('prompts a guest to sign in instead of fetching', () => {
  useSessionStore.setState({ user: null, status: 'guest' });
  render(<MyPlacesScreen />, { wrapper: Providers });

  expect(screen.getByText('Your collection lives here')).toBeOnTheScreen();
  expect(mock.history.get.filter((r) => r.url === '/me/places')).toHaveLength(0);
});

it('shows the empty state when the collection is empty', async () => {
  mock.onGet('/me/places').reply(200, page([]));

  render(<MyPlacesScreen />, { wrapper: Providers });
  expect(await screen.findByText('No places yet')).toBeOnTheScreen();
});

it('re-fetches with country filter when a facet chip is toggled', async () => {
  mock.onGet('/me/places', { params: { limit: 20, sort: 'recent' } }).reply(200, page([place('1', { country_code: 'PT' })]));
  mock
    .onGet('/me/places', { params: { limit: 20, sort: 'recent', country: 'PT' } })
    .reply(200, page([place('1', { country_code: 'PT' })]));

  render(<MyPlacesScreen />, { wrapper: Providers });
  await screen.findByText('Place 1');

  // The country facet chip is derived from the loaded rows (PT).
  fireEvent.press(screen.getByText('PT'));
  await waitFor(() =>
    expect(mock.history.get.some((r) => r.url === '/me/places' && r.params?.country === 'PT')).toBe(true),
  );
});

/** Auto-tap the destructive button in the confirmation Alert. */
function confirmAlert() {
  return jest.spyOn(Alert, 'alert').mockImplementation((_title, _msg, buttons) => {
    (buttons as AlertButton[] | undefined)?.find((b) => b.style === 'destructive')?.onPress?.();
  });
}

it('confirms, then removes a shared place — soft-hides my share and it drops out', async () => {
  const alertSpy = confirmAlert();
  let posted: number | null = null;
  // The backend drops a dismissed share from my collection, so the settle
  // refetch returns the list without it — mirror that in the mock.
  mock.onGet('/me/places').reply(() =>
    posted === null ? [200, page([place('1', { mine: { share_id: '1', saved: false } }), place('2')])] : [200, page([place('2')])],
  );
  mock.onPost('/feed/hidden').reply((cfg) => {
    posted = JSON.parse(cfg.data).share_id;
    return [201];
  });

  render(<MyPlacesScreen />, { wrapper: Providers });
  await screen.findByText('Place 1');

  fireEvent.press(screen.getAllByLabelText('Remove')[0]);
  expect(alertSpy).toHaveBeenCalled();

  await waitFor(() => expect(posted).toBe(1));
  await waitFor(() => expect(screen.queryByText('Place 1')).toBeNull());
  expect(screen.getByText('Place 2')).toBeOnTheScreen();
  alertSpy.mockRestore();
});

it('does not remove when the confirmation is cancelled', async () => {
  const alertSpy = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
  mock.onGet('/me/places').reply(200, page([place('1')]));
  mock.onPost('/feed/hidden').reply(201);

  render(<MyPlacesScreen />, { wrapper: Providers });
  await screen.findByText('Place 1');
  fireEvent.press(screen.getAllByLabelText('Remove')[0]);

  expect(alertSpy).toHaveBeenCalled();
  expect(mock.history.post).toHaveLength(0);
  expect(screen.getByText('Place 1')).toBeOnTheScreen();
  alertSpy.mockRestore();
});

it('appends the next page when the list reaches its end', async () => {
  mock.onGet('/me/places', { params: { limit: 20, sort: 'recent' } }).reply(200, page([place('1')], 'CUR'));
  mock
    .onGet('/me/places', { params: { limit: 20, sort: 'recent', cursor: 'CUR' } })
    .reply(200, page([place('2')]));

  render(<MyPlacesScreen />, { wrapper: Providers });
  await screen.findByText('Place 1');
  expect(screen.queryByText('Place 2')).toBeNull();

  fireEvent.press(screen.getByTestId('flash-list-end'));
  expect(await screen.findByText('Place 2')).toBeOnTheScreen();
});
