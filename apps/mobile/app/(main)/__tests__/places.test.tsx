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
  mock.onGet('/me/places/tags').reply(200, { data: [] });
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

it('prompts a guest to sign in without ever fetching /me/places', async () => {
  useSessionStore.setState({ user: null, status: 'guest' });
  mock.onGet('/me/places').reply(200, page([place('1')]));

  render(<MyPlacesScreen />, { wrapper: Providers });

  expect(screen.getByText('Your collection lives here')).toBeOnTheScreen();
  // enabled:false must actually suppress the (auth-only) request — a real fetch
  // would 401 and bounce the guest to login. Flush microtasks, then assert none.
  await waitFor(() => expect(screen.getByText('Sign in')).toBeOnTheScreen());
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

  // The country facet lives in the filter sheet, derived from the loaded rows (PT).
  fireEvent.press(screen.getByLabelText('Filters'));
  fireEvent.press(await screen.findByLabelText('PT'));
  await waitFor(() =>
    expect(mock.history.get.some((r) => r.url === '/me/places' && r.params?.country === 'PT')).toBe(true),
  );
});

it('keeps all country facet chips after filtering, so you can switch directly (BUG G)', async () => {
  // Unfiltered (facet source) has PT + AR; filtering to PT narrows the LIST only.
  mock.onGet('/me/places', { params: { limit: 20, sort: 'recent' } })
    .reply(200, page([place('1', { country_code: 'PT' }), place('2', { country_code: 'AR' })]));
  mock.onGet('/me/places', { params: { limit: 20, sort: 'recent', country: 'PT' } })
    .reply(200, page([place('1', { country_code: 'PT' })]));

  render(<MyPlacesScreen />, { wrapper: Providers });
  await screen.findByText('Place 1');

  // Both country options are offered in the filter sheet.
  fireEvent.press(screen.getByLabelText('Filters'));
  expect(await screen.findByLabelText('PT')).toBeOnTheScreen();
  expect(screen.getByLabelText('AR')).toBeOnTheScreen();

  fireEvent.press(screen.getByLabelText('PT'));
  // After filtering to PT, the AR option must NOT vanish (facets come from the
  // unfiltered set) — you can switch straight to AR.
  await waitFor(() => expect(screen.queryByText('Place 2')).toBeNull());
  expect(screen.getByLabelText('AR')).toBeOnTheScreen();
});

/** Auto-tap a labelled button in the remove Alert (default: the destructive one). */
function tapAlert(match: (b: AlertButton) => boolean = (b) => b.style === 'destructive') {
  return jest.spyOn(Alert, 'alert').mockImplementation((_title, _msg, buttons) => {
    (buttons as AlertButton[] | undefined)?.find(match)?.onPress?.();
  });
}

it('fully removes a place (destructive choice) via DELETE ?mode=full and it drops out', async () => {
  const alertSpy = tapAlert();
  let deletedMode: string | null = null;
  // The server removes it, so the settle refetch omits it.
  mock.onGet('/me/places').reply(() =>
    deletedMode === null ? [200, page([place('1'), place('2')])] : [200, page([place('2')])],
  );
  mock.onDelete('/me/places/1').reply((cfg) => {
    deletedMode = cfg.params?.mode;
    return [204];
  });

  render(<MyPlacesScreen />, { wrapper: Providers });
  await screen.findByText('Place 1');

  fireEvent.press(screen.getAllByLabelText('Remove')[0]);
  expect(alertSpy).toHaveBeenCalled();

  await waitFor(() => expect(deletedMode).toBe('full'));
  await waitFor(() => expect(screen.queryByText('Place 1')).toBeNull());
  expect(screen.getByText('Place 2')).toBeOnTheScreen();
  alertSpy.mockRestore();
});

it('hides a place (non-destructive choice) via DELETE ?mode=hide', async () => {
  const alertSpy = tapAlert((b) => b.text === 'Hide from map');
  let deletedMode: string | null = null;
  mock.onGet('/me/places').reply(() =>
    deletedMode === null ? [200, page([place('1'), place('2')])] : [200, page([place('2')])],
  );
  mock.onDelete('/me/places/1').reply((cfg) => {
    deletedMode = cfg.params?.mode;
    return [204];
  });

  render(<MyPlacesScreen />, { wrapper: Providers });
  await screen.findByText('Place 1');
  fireEvent.press(screen.getAllByLabelText('Remove')[0]);

  await waitFor(() => expect(deletedMode).toBe('hide'));
  alertSpy.mockRestore();
});

it('removes a saved-only place through the same DELETE (server handles un-save)', async () => {
  const alertSpy = tapAlert();
  let deleted = false;
  mock.onGet('/me/places').reply(() =>
    !deleted ? [200, page([place('9', { mine: { share_id: null, saved: true } })])] : [200, page([])],
  );
  mock.onDelete('/me/places/9').reply(() => {
    deleted = true;
    return [204];
  });

  render(<MyPlacesScreen />, { wrapper: Providers });
  await screen.findByText('Place 9');
  fireEvent.press(screen.getAllByLabelText('Remove')[0]);

  await waitFor(() => expect(deleted).toBe(true));
  await waitFor(() => expect(screen.queryByText('Place 9')).toBeNull());
  alertSpy.mockRestore();
});

it('does not remove when the confirmation is cancelled', async () => {
  const alertSpy = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
  mock.onGet('/me/places').reply(200, page([place('1')]));
  mock.onDelete('/me/places/1').reply(204);

  render(<MyPlacesScreen />, { wrapper: Providers });
  await screen.findByText('Place 1');
  fireEvent.press(screen.getAllByLabelText('Remove')[0]);

  expect(alertSpy).toHaveBeenCalled();
  expect(mock.history.delete).toHaveLength(0);
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
