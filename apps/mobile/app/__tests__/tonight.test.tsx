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

it('sends a dish at the API floor, and never one below it', async () => {
  await renderTonight();

  // A BARRIER first. The earlier version of this test asserted only that no
  // request had appeared, and `waitFor` runs its callback synchronously on the
  // first pass — so it resolved at t≈0, before any request could have landed,
  // and stayed green with the floor mutated to 1. It also never pinned the
  // floor's VALUE: raising it to 5 left every assertion here passing.
  //
  // So: type exactly at the floor, WAIT for that request, and only then check
  // that the shorter one never produced one.
  fireEvent.changeText(screen.getByTestId('tonight-dish'), 'pas');
  jest.advanceTimersByTime(400);

  await waitFor(() => expect(requests().at(-1)).toMatchObject({ dish: 'pas' }));

  const atFloor = requests().length;

  // One below the floor. Three characters is where pg_trgm can extract a
  // trigram at all; below it the API would 422 a half-typed word, so a short
  // entry has to narrow nothing rather than empty the screen.
  fireEvent.changeText(screen.getByTestId('tonight-dish'), 'pa');
  jest.advanceTimersByTime(400);

  await waitFor(() => expect(requests().length).toBeGreaterThan(atFloor));
  expect(requests().at(-1)).not.toHaveProperty('dish');
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

it('tells the three location outcomes apart, and queries on none of them', async () => {
  // Only 'denied' was ever mocked here, which is how `unavailable` — permission
  // GRANTED, the fix timed out — came to be shown "Location is off for Reelmap"
  // and an Open Settings button pointing at a switch that is already on.
  const cases = [
    { reason: 'denied' as const, text: /needs your location/i, cta: 'Try again' },
    { reason: 'blocked' as const, text: /needs your location/i, cta: 'Open Settings' },
    { reason: 'unavailable' as const, text: /couldn.t get your location/i, cta: 'Try again' },
  ];

  for (const c of cases) {
    mockedLocate.mockResolvedValue({ ok: false, reason: c.reason });
    qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
    const view = render(<TonightScreen />, { wrapper });

    await waitFor(() => expect(view.getByTestId('tonight-location')).toBeTruthy());
    expect(view.getByText(c.text)).toBeTruthy();
    expect(view.getByText(c.cta)).toBeTruthy();
    view.unmount();
  }

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

it('a dish chip puts into the box the SAME words it shows, not the English slug', async () => {
  // The whole suggestion row was uncovered: `/tags` was stubbed to `[]` in every
  // test, so nothing exercised it. The bug that hid there is that a chip renders
  // its LOCALIZED label ("Hamburguesas") while `tag.name` is the canonical
  // English slug ("burger") — and `?dish=` matches verbatim Uruguayan menu text,
  // so tapping the chip wrote a word no menu contains and emptied the list.
  mock.onGet('/tags').reply(200, {
    data: [{ id: '1', name: 'burger', slug: 'burger', kind: 'dish', label: 'Hamburguesas' }],
  });
  await renderTonight();

  const chip = await screen.findByText('Hamburguesas');
  const before = requests().length;
  fireEvent.press(chip);
  jest.advanceTimersByTime(400);

  await waitFor(() => expect(requests().length).toBeGreaterThan(before));
  expect(requests().at(-1)).toMatchObject({ dish: 'Hamburguesas' });
  // And the field shows what was queried, rather than disagreeing with it.
  expect(screen.getByTestId('tonight-dish').props.value).toBe('Hamburguesas');
});

it('does not state a page count as though it were a total', async () => {
  // `places.length` is what has been paged in. With another page behind it,
  // saying "20 places" would become "40 places" on scroll — the one line that
  // exists to explain the dials, changing for a reason unrelated to them.
  mock.onGet('/places').reply(200, {
    data: Array.from({ length: 20 }, (_, i) => place({ id: String(i + 1), slug: `p-${i + 1}` })),
    meta: { pagination: { next_cursor: 'c2', prev_cursor: null, limit: 20 } },
  });
  await renderTonight();

  await waitFor(() => expect(screen.getByTestId('tonight-answer').props.children).toMatch(/20\+/));
});

it('states an exact count when there is no page behind it', async () => {
  await renderTonight();

  await waitFor(() => expect(screen.getByTestId('tonight-answer').props.children).toMatch(/^1 place open/));
});
