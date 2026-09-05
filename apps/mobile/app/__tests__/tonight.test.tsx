import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import TonightScreen from '../(main)/tonight';
import { api } from '@/api/client';
import type { PlaceSummary } from '@/api/places';
import { locateUser } from '@/lib/initial-region';

jest.mock('@/lib/initial-region', () => ({
  ...jest.requireActual('@/lib/initial-region'),
  locateUser: jest.fn(),
}));

const mockedLocate = locateUser as jest.MockedFunction<typeof locateUser>;

let mock: AxiosMockAdapter;
let qc: QueryClient;

/**
 * Tonight (T-158) — "where do I eat, here, now".
 *
 * The rules pinned here are the acceptance's, and every one of them is about
 * the LOOP rather than the first paint: a screen that renders a correct list
 * once and then never re-asks looks perfect in a screenshot and is useless in
 * the hand. So each of the three inputs is changed and the resulting REQUEST is
 * inspected — not the rendered rows, which would pass on a cache hit.
 */
function place(overrides: Partial<PlaceSummary> = {}): PlaceSummary {
  return {
    id: '1',
    name: 'Ganache',
    slug: 'ganache',
    status: 'active',
    lat: -34.9,
    lng: -56.16,
    category: 'italian',
    price_range: 2,
    city: 'Montevideo',
    country_code: 'UY',
    source_count: 1,
    rating: { google: { value: null, count: 0 } },
    distance_m: null,
    created_at: null,
    thumbnail_url: null,
    ...overrides,
  } as PlaceSummary;
}

/** Every `/places` request this render issued, in order, as param maps. */
function requests(): Record<string, string>[] {
  return mock.history.get
    .filter((r) => r.url === '/places')
    .map((r) => Object.fromEntries(Object.entries(r.params ?? {}).map(([k, v]) => [k, String(v)])));
}

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  jest.useFakeTimers();
  mock = new AxiosMockAdapter(api);
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mockedLocate.mockResolvedValue({ ok: true, region: { latitude: -34.9011, longitude: -56.1645, latitudeDelta: 0.02, longitudeDelta: 0.02 } });
  mock.onGet('/tags').reply(200, { data: [] });
  mock.onGet('/places').reply(200, {
    data: [place()],
    meta: { pagination: { next_cursor: null, prev_cursor: null, limit: 20 } },
  });
});

afterEach(() => {
  mock.restore();
  jest.useRealTimers();
});

async function renderTonight() {
  render(<TonightScreen />, { wrapper });
  await waitFor(() => expect(requests().length).toBeGreaterThan(0));
}

it('asks around the viewer, open-now, nearest first', async () => {
  await renderTonight();

  expect(requests()[0]).toMatchObject({
    near: '-34.9011,-56.1645',
    radius_m: '2000',
    open_now: '1',
    sort: 'distance',
  });
});

it('RE-ASKS when the zone changes', async () => {
  await renderTonight();
  const before = requests().length;

  fireEvent.press(screen.getByText('5 km'));

  await waitFor(() => expect(requests().length).toBeGreaterThan(before));
  expect(requests().at(-1)).toMatchObject({ radius_m: '5000' });
});

it('RE-ASKS when open-now is turned off, and stops sending the filter', async () => {
  await renderTonight();
  const before = requests().length;

  fireEvent.press(screen.getByText('Open now'));

  await waitFor(() => expect(requests().length).toBeGreaterThan(before));
  // Absent, not `open_now=0`: the parameter is the filter, and sending it off
  // would put it in the cache key of requests that do not filter.
  expect(requests().at(-1)).not.toHaveProperty('open_now');
});

it('RE-ASKS when the dish changes, once the typing settles', async () => {
  await renderTonight();
  const before = requests().length;

  fireEvent.changeText(screen.getByTestId('tonight-dish'), 'pasta');

  // Debounced: the request must NOT have gone out on the keystroke.
  expect(requests().length).toBe(before);

  jest.advanceTimersByTime(400);

  await waitFor(() => expect(requests().length).toBeGreaterThan(before));
  expect(requests().at(-1)).toMatchObject({ dish: 'pasta' });
});

it('does not send a dish too short for the API to match', async () => {
  await renderTonight();
  const before = requests().length;

  // The API floor is three characters; below it pg_trgm extracts no trigram at
  // all. Sending "pa" would be a 422 on a half-typed word — so a short entry
  // narrows nothing rather than emptying the screen.
  fireEvent.changeText(screen.getByTestId('tonight-dish'), 'pa');
  jest.advanceTimersByTime(400);

  await waitFor(() => expect(requests().length).toBe(before));
});

it('renders an EMPTY result and a FAILED request differently, and only the failure offers a retry', async () => {
  mock.onGet('/places').reply(200, {
    data: [],
    meta: { pagination: { next_cursor: null, prev_cursor: null, limit: 20 } },
  });
  await renderTonight();

  await waitFor(() => expect(screen.getByTestId('tonight-empty')).toBeTruthy());
  // An empty answer is not an error: offering "Try again" here would send the
  // user to re-run a query that ran perfectly well and found nothing.
  expect(screen.queryByText('Try again')).toBeNull();
  expect(screen.queryByTestId('tonight-error')).toBeNull();
});

it('offers a retry when the request FAILS, and the retry re-asks', async () => {
  mock.onGet('/places').reply(500);
  await renderTonight();

  await waitFor(() => expect(screen.getByTestId('tonight-error')).toBeTruthy());
  expect(screen.queryByTestId('tonight-empty')).toBeNull();

  const before = requests().length;
  fireEvent.press(screen.getByText('Try again'));

  await waitFor(() => expect(requests().length).toBeGreaterThan(before));
});

it('asks for location and says what is missing when it is refused, without querying', async () => {
  mockedLocate.mockResolvedValue({ ok: false, reason: 'denied' });
  render(<TonightScreen />, { wrapper });

  await waitFor(() => expect(screen.getByText(/needs your location/i)).toBeTruthy());
  // "near you" with no fix is either an empty screen or a list from a city the
  // diner is not in. Neither is worth a request.
  expect(requests()).toEqual([]);
});

it('virtualizes the list, and reaching the end asks for the NEXT page', async () => {
  mock.onGet('/places').reply(200, {
    data: Array.from({ length: 20 }, (_, i) => place({ id: String(i + 1), slug: `p-${i + 1}` })),
    meta: { pagination: { next_cursor: 'c2', prev_cursor: null, limit: 20 } },
  });
  await renderTonight();

  // `flash-list` is the harness's stand-in for FlashList, so its presence is
  // what says this is a virtualized list rather than rows `.map()`ed into a
  // ScrollView — a difference invisible in a test that only counts rows, and
  // very visible at 200 places on a mid-range phone.
  expect(await screen.findByTestId('flash-list')).toBeTruthy();

  const before = requests().length;
  fireEvent.press(screen.getByTestId('flash-list-end'));

  await waitFor(() => expect(requests().length).toBeGreaterThan(before));
  expect(requests().at(-1)).toMatchObject({ cursor: 'c2' });
});
