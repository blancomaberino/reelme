import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';
import { Linking, Share } from 'react-native';

import PlaceDetailScreen from '../[slug]';

import { LA_DIECISIETE, SCHEMA_ORG, SPANISH } from '@/test/opening-hours-fixtures';
import { api } from '@/api/client';
import type { PlaceDetail } from '@/api/places';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../../../jest.setup';

let mock: AxiosMockAdapter;
let qc: QueryClient;

const PLACE: PlaceDetail = {
  id: '4',
  name: '1921 Restaurant',
  slug: '1921-restaurant-ljunrd',
  status: 'pending',
  lat: -34.890555,
  lng: -56.055278,
  category: 'modern',
  price_range: 3,
  city: 'Montevideo',
  country_code: 'UY',
  address: 'Rbla. República de México, Montevideo, UY',
  google_place_id: 'ChIJn-slTW6Gn5URoY55e-CgaHY',
  opening_hours: null,
  phone: '+59829021621',
  website: 'https://sofitel.com',
  image_url: null,
  thumbnail_url: null,
  cuisines: ['modern', 'seafood'],
  vibe_tags: ['fine dining'],
  dietary_tags: [],
  dishes: [
    { name: 'Ojo de bife', shown_in_video: false, price: '$780' },
    { name: 'Flan', shown_in_video: true, price: null },
  ],
  dishes_updated_at: '2026-07-10T12:00:00Z',
  dishes_language: 'en',
  source_count: 1,
  rating: { google: { value: 4.5, count: 527 }, app: { value: null, count: 0 } },
  discounts: [],
  sources: [
    {
      id: '4',
      is_primary: true,
      source_post: {
        platform: 'instagram',
        url: 'https://www.instagram.com/reel/DatKubIhOX8/',
        caption: 'Cenar en el Sofitel Montevideo',
        posted_at: null,
        thumbnail_url: null,
      },
      influencer: { id: '2', platform: 'instagram', handle: 'comeren.uy', display_name: 'comeren.uy', avatar_url: null },
      sharer: { id: '6', username: 'foodie', name: 'Foodie', avatar_path: null },
      highlights: { dishes: ['Ojo de bife'], tags: ['modern'] },
    },
  ],
};

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.params = { slug: PLACE.slug };
  jest.spyOn(Linking, 'openURL').mockResolvedValue(true);
  jest.spyOn(Share, 'share').mockResolvedValue({ action: 'sharedAction' } as never);
});

afterEach(() => {
  mock.restore();
  qc.clear();
  jest.restoreAllMocks();
  useSessionStore.setState({ user: null, status: 'guest' });
});

it('renders the place name, cuisine, rating and address', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });

  render(<PlaceDetailScreen />, { wrapper: Providers });

  expect(await screen.findByText('1921 Restaurant')).toBeOnTheScreen();
  // Category is title-cased + priced in the chosen currency ($ by default).
  expect(screen.getByText(/Modern · \$\$\$/)).toBeOnTheScreen();
  expect(screen.getByText(/4\.5/)).toBeOnTheScreen();
  expect(screen.getByText(/Rbla\. República/)).toBeOnTheScreen();
  // Tag chip from cuisines/vibe_tags union (title-cased for display).
  expect(screen.getByText('Fine Dining')).toBeOnTheScreen();
  // A Google Maps link shows when google_place_id is present.
  expect(screen.getByText('View on Google Maps')).toBeOnTheScreen();
  // Dishes are behind a "View menu" button (with an item count), not inline.
  expect(screen.getByText('View menu')).toBeOnTheScreen();
  expect(screen.getByText('2 items')).toBeOnTheScreen();
});

it('opens the menu sheet with dish prices, updated date, and the source', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });

  render(<PlaceDetailScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByText('View menu'));

  // Dishes + prices in the sheet.
  expect(await screen.findByText(/Ojo de bife/)).toBeOnTheScreen();
  expect(screen.getByText('$780')).toBeOnTheScreen();
  // Updated date (jest locale is English) + extraction source reference.
  expect(screen.getByText(/Menu updated Jul 10, 2026/)).toBeOnTheScreen();
  expect(screen.getByText('Extracted from')).toBeOnTheScreen();
  // @comeren.uy appears both on the source card and in the menu's source ref.
  expect(screen.getAllByText('@comeren.uy').length).toBeGreaterThan(1);
});

it('links out to the original post when a source card is tapped', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });

  render(<PlaceDetailScreen />, { wrapper: Providers });

  const card = await screen.findByLabelText('Open original instagram post');
  fireEvent.press(card);
  expect(Linking.openURL).toHaveBeenCalledWith('https://www.instagram.com/reel/DatKubIhOX8/');
});

it('opens the native share sheet with the deep link', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });

  render(<PlaceDetailScreen />, { wrapper: Providers });

  fireEvent.press(await screen.findByLabelText('Share'));
  expect(Share.share).toHaveBeenCalledWith(
    expect.objectContaining({ url: 'reelmap://place/1921-restaurant-ljunrd' }),
  );
});

it('dials the phone number via tel:', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });

  render(<PlaceDetailScreen />, { wrapper: Providers });

  fireEvent.press(await screen.findByText('+59829021621'));
  expect(Linking.openURL).toHaveBeenCalledWith('tel:+59829021621');
});

it('opens directions in the maps app', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });

  render(<PlaceDetailScreen />, { wrapper: Providers });

  fireEvent.press(await screen.findByLabelText('Directions'));
  // Default Platform.OS in jest-expo is 'ios' → Apple Maps URL.
  expect(Linking.openURL).toHaveBeenCalledWith(expect.stringContaining('maps.apple.com'));
});

/**
 * Opening hours (T-128).
 *
 * These live at the SCREEN level on purpose. The unit tests for
 * `summarizeHours` were green for months while this row rendered for nobody:
 * they fed it the `{periods, weekday_text}` object nothing in the API has ever
 * stored, so they agreed with a function that returned `label: null` for the
 * real payload — and the screen gated the whole row on that label. Only a test
 * that drives the screen with the shape the API actually sends can catch that.
 *
 * The hour fixtures live in src/test/opening-hours-fixtures.ts — shared with the
 * `hourLines` unit test, because they are valuable precisely for their BYTES
 * (Google's U+2009/U+202F) and two copies had already diverged on how they
 * escaped them.
 */
/**
 * The plain strings a subtree actually renders, in order. `Row` wraps its child
 * in nodes whose `children` is `['', undefined]`, so those are dropped — but
 * every real string is kept, including one this screen must never show
 * ("Open now"), which is what makes an exact `toEqual` a usable guard rather
 * than an unfalsifiable `queryByText(...).toBeNull()`.
 */
function visibleText(node: Parameters<typeof within>[0]): string[] {
  return within(node)
    .getAllByText(/.+/)
    .map((n) => n.props.children)
    .filter((c): c is string => typeof c === 'string' && c.trim() !== '');
}

describe('opening hours (T-128)', () => {
  // Annotated, so tsc holds these fixtures to the contract the way the review
  // fixtures below are held — an unannotated object literal would let a wrong
  // `opening_hours` shape back in through the very test that guards it.
  const withHours = (opening_hours: PlaceDetail['opening_hours']): PlaceDetail => ({
    ...PLACE,
    opening_hours,
  });

  it('shows the hours row and expands the source lines verbatim', async () => {
    mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: withHours(LA_DIECISIETE) });

    render(<PlaceDetailScreen />, { wrapper: Providers });

    // The row itself is on screen — this is the assertion that was missing.
    const row = await screen.findByTestId('place-hours');
    expect(screen.getByText('Opening hours')).toBeOnTheScreen();
    // Collapsed: the lines are not shown yet, and the row says ONLY the neutral
    // label. Asserted positively — `queryByText(/Open now/)).toBeNull()` reads
    // like a guard against an open/closed claim and is unfalsifiable, because
    // that string exists nowhere in the app. Pinning what the row DOES say is
    // what actually fails if a summary badge is ever reintroduced.
    expect(screen.queryByTestId('place-hours-weekly')).toBeNull();
    expect(visibleText(row)).toEqual(['Opening hours']);
    expect(row.props.accessibilityState).toMatchObject({ expanded: false });
    expect(row.props.accessibilityLabel).toBe('Show weekly hours');

    fireEvent.press(row);

    // Expanded: every line, exactly as the source wrote it, IN ORDER. A
    // per-line `getByText` loop passes on a re-sorted or re-worded list; this
    // compares the rendered sequence to the payload, so a `.sort()`, a dropped
    // row, or a whitespace "cleanup" of the U+2009/U+202F all turn it red.
    const weekly = screen.getByTestId('place-hours-weekly');
    expect(weekly).toBeOnTheScreen();
    expect(visibleText(weekly)).toEqual(LA_DIECISIETE);
    expect(screen.getByTestId('place-hours').props.accessibilityState).toMatchObject({ expanded: true });
    expect(screen.getByTestId('place-hours').props.accessibilityLabel).toBe('Hide weekly hours');

    // And it collapses again — the toggle is a loop, not a one-way door.
    fireEvent.press(screen.getByTestId('place-hours'));
    expect(screen.queryByTestId('place-hours-weekly')).toBeNull();
  });

  it('renders schema.org rule lines just as happily', async () => {
    // `WebsiteBusinessSource` writes these; they are not Google's wording and
    // carry no day-name-and-colon prefix at all.
    mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: withHours(SCHEMA_ORG) });

    render(<PlaceDetailScreen />, { wrapper: Providers });
    fireEvent.press(await screen.findByTestId('place-hours'));

    expect(visibleText(screen.getByTestId('place-hours-weekly'))).toEqual(SCHEMA_ORG);
  });

  it("keeps a Spanish source's own words, and repeated days do not swallow each other", async () => {
    // Two identical "Cerrado" lines: keyed by text they would collide and React
    // would render one. Uruguay is the launch market, so this is the norm here.
    mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: withHours(SPANISH) });

    render(<PlaceDetailScreen />, { wrapper: Providers });
    fireEvent.press(await screen.findByTestId('place-hours'));

    expect(visibleText(screen.getByTestId('place-hours-weekly'))).toEqual(SPANISH);
    expect(screen.getAllByText('Cerrado')).toHaveLength(2);
  });

  it('shows no hours row at all when the place has none — never a bare "Closed"', async () => {
    // PLACE.opening_hours is null. Absent hours must read as absent, not shut.
    mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });

    render(<PlaceDetailScreen />, { wrapper: Providers });
    await screen.findByText('1921 Restaurant');

    expect(screen.queryByTestId('place-hours')).toBeNull();
    expect(screen.queryByText('Opening hours')).toBeNull();
    expect(screen.queryByText(/Closed/)).toBeNull();
  });
});

it('shows the not-found state on a 404', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(404, { error: { message: 'not found' } });

  render(<PlaceDetailScreen />, { wrapper: Providers });

  expect(await screen.findByText('Place not found')).toBeOnTheScreen();
});

describe('loading state (T-108)', () => {
  it('shows the skeleton while the place is in flight, then swaps in the real body', async () => {
    // Held open so the loading state is observable rather than a race. The
    // deferred is built up front — the reply handler doesn't run synchronously,
    // so capturing `resolve` inside it would still be undefined down here.
    let release!: (v: [number, unknown]) => void;
    const pending = new Promise<[number, unknown]>((resolve) => (release = resolve));
    mock.onGet(`/places/${PLACE.slug}`).reply(() => pending);

    render(<PlaceDetailScreen />, { wrapper: Providers });

    const skeleton = screen.getByTestId('place-skeleton');
    expect(skeleton).toBeOnTheScreen();
    // It announces itself as loading — the spinner it replaces said nothing.
    expect(skeleton.props.accessibilityLabel).toBe('Loading');
    // Loading is NOT the error state: no retry affordance, nothing gone wrong.
    expect(screen.queryByText('Place not found')).toBeNull();
    expect(screen.queryByText('Try again')).toBeNull();

    release([200, { data: PLACE }]);

    expect(await screen.findByText('1921 Restaurant')).toBeOnTheScreen();
    expect(screen.queryByTestId('place-skeleton')).toBeNull();
  });

  it('scrolls, so a tall skeleton is not clipped on a short screen', async () => {
    // Signed in, the skeleton is ~835pt — taller than a 4.7" viewport. As a
    // plain View the mini-map and the action pair were silently cut off.
    const pending = new Promise<[number, unknown]>(() => {});
    mock.onGet(`/places/${PLACE.slug}`).reply(() => pending);
    useSessionStore.setState({ user: null, status: 'authed' });

    render(<PlaceDetailScreen />, { wrapper: Providers });

    expect(screen.getByTestId('place-skeleton-scroll')).toBeOnTheScreen();
  });

  it('reserves the my-tags block only for a signed-in viewer', async () => {
    // My tags renders only when authed, so a skeleton that always (or never)
    // reserved it would shift everything below the chips by ~90pt for half the
    // users. Count the placeholder blocks with and without a session.
    const pending = new Promise<[number, unknown]>(() => {});
    mock.onGet(`/places/${PLACE.slug}`).reply(() => pending);
    const blocks = () =>
      screen.getByTestId('place-skeleton', { includeHiddenElements: true }).children.length;

    const guest = render(<PlaceDetailScreen />, { wrapper: Providers });
    const asGuest = blocks();
    guest.unmount();

    useSessionStore.setState({ user: null, status: 'authed' });
    render(<PlaceDetailScreen />, { wrapper: Providers });

    // Exactly one extra child: the my-tags group.
    expect(blocks()).toBe(asGuest + 1);
  });

  it('replaces the skeleton with the error state on failure, not with both', async () => {
    mock.onGet(`/places/${PLACE.slug}`).reply(500);

    render(<PlaceDetailScreen />, { wrapper: Providers });

    expect(await screen.findByText('Place not found')).toBeOnTheScreen();
    expect(screen.queryByTestId('place-skeleton')).toBeNull();
    expect(screen.getByText('Try again')).toBeOnTheScreen();
  });
});

it('renders app + Google reviews with names, stars and text', async () => {
  const withReviews: PlaceDetail = {
    ...PLACE,
    rating: { google: { value: 4.5, count: 527 }, app: { value: 5, count: 1 } },
    reviews: [
      {
        id: '9',
        rating: 5,
        body: 'Impecable, volvería.',
        author: { id: '6', username: 'foodie', name: 'Foodie', avatar_path: null },
        is_own: false,
        created_at: '2026-07-01T00:00:00Z',
        updated_at: '2026-07-01T00:00:00Z',
      },
    ],
    google_reviews: [
      {
        author: 'Ana Pérez',
        rating: 4,
        text: 'Buena comida y atención.',
        relative_time: 'hace 2 semanas',
        profile_photo_url: 'https://lh3.googleusercontent.com/a/ana.jpg',
      },
      { author: 'Sin foto', rating: 3, text: 'Correcto.', profile_photo_url: null },
    ],
  };
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: withReviews });

  render(<PlaceDetailScreen />, { wrapper: Providers });

  expect(await screen.findByText('Reviews')).toBeOnTheScreen();
  // The app review renders its body (the @foodie name also appears on the
  // source card, so the body is the unambiguous signal it rendered).
  expect(screen.getByText('Impecable, volvería.')).toBeOnTheScreen();
  expect(screen.getByText('From Google')).toBeOnTheScreen();
  // Google review name + relative time + stars share a Text node → substring match.
  expect(screen.getByText(/Ana Pérez/)).toBeOnTheScreen();
  expect(screen.getByText(/hace 2 semanas/)).toBeOnTheScreen();
  expect(screen.getByText('Buena comida y atención.')).toBeOnTheScreen();
  // The photo-less Google review falls back to an initial avatar, not a crash.
  expect(screen.getByText(/Sin foto/)).toBeOnTheScreen();
});

it('taps the sharer’s handle through to their profile', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });

  render(<PlaceDetailScreen />, { wrapper: Providers });

  // Styled to look tappable since T-033 and inert until now. It matters beyond
  // a dead affordance: a place is where you meet someone else's content, and
  // the only other routes to a profile are search (you must already know the
  // handle) and a follow list — so with this inert, "block an abusive user"
  // meant retyping their username somewhere else.
  fireEvent.press(await screen.findByTestId('source-sharer-foodie'));

  expect(mockRouter.push).toHaveBeenCalledWith({
    pathname: '/users/[username]',
    params: { username: 'foodie' },
  });
});

it('taps the influencer’s handle through to their page', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });

  render(<PlaceDetailScreen />, { wrapper: Providers });

  // Both handles navigate, which is also what removes the ambiguity: two
  // look-alike handles where only one was a link read as neither being one.
  fireEvent.press(await screen.findByTestId('source-influencer-comeren.uy'));

  expect(mockRouter.push).toHaveBeenCalledWith({
    pathname: '/influencer/[id]',
    params: { id: '2' },
  });
});

it('announces an anonymised sharer as text, not as a dead button', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(200, {
    data: { ...PLACE, sources: [{ ...PLACE.sources![0], sharer: null }] },
  });

  render(<PlaceDetailScreen />, { wrapper: Providers });
  await screen.findByText(PLACE.name);

  // The chevron is the app's "this navigates" signal and must be absent here,
  // and the row must not claim `button` — a private sharer is still worth
  // announcing, just not as a control. Asserted through the ROLE, which is what
  // a screen reader actually says.
  expect(screen.queryByRole('button', { name: /Someone|Alguien/ })).toBeNull();
});

it('does not offer a profile tap when the sharer is anonymised', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(200, {
    data: { ...PLACE, sources: [{ ...PLACE.sources![0], sharer: null }] },
  });

  render(<PlaceDetailScreen />, { wrapper: Providers });

  await screen.findByText(PLACE.name);
  // A private sharer is nulled by the API. A tap target here would navigate to
  // `/users/undefined`.
  expect(screen.queryByTestId(/^source-sharer-/)).toBeNull();
});

it('reports a review through the review endpoint, with review-specific reasons', async () => {
  const withReview: PlaceDetail = {
    ...PLACE,
    reviews: [
      {
        id: '9',
        rating: 5,
        body: 'Impecable, volvería.',
        author: { id: '6', username: 'foodie', name: 'Foodie', avatar_path: null },
        is_own: false,
        created_at: '2026-07-01T00:00:00Z',
        updated_at: '2026-07-01T00:00:00Z',
      },
    ],
  };
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: withReview });
  mock.onPost('/reviews/9/report').reply(200, { data: { reported: true } });
  // The sheet offers a sign-in prompt instead of reasons to a guest.
  useSessionStore.setState({ user: null, status: 'authed' });

  render(<PlaceDetailScreen />, { wrapper: Providers });
  await screen.findByText('Impecable, volvería.');

  // Distinct from the place's own Report control, which sits on the same
  // screen — two buttons labelled just "Report" are ambiguous to a screen
  // reader, and this test is what caught that.
  fireEvent.press(screen.getByLabelText('Report the review by @foodie'));

  // The REVIEW vocabulary, not the general one: `off_topic` is meaningful for a
  // review, and `wrong_place` would be a reason the server rejects — a 422 the
  // user reads as "reporting is broken".
  expect(await screen.findByTestId('report-reason-off_topic')).toBeOnTheScreen();
  expect(screen.queryByTestId('report-reason-wrong_place')).toBeNull();

  // And no free-text box: the review endpoint takes a reason and nothing else,
  // so a field here would collect an explanation and silently discard it.
  expect(screen.queryByTestId('report-details')).toBeNull();

  fireEvent.press(screen.getByTestId('report-reason-off_topic'));
  fireEvent.press(screen.getByTestId('report-submit'));

  // Its own endpoint, and a bare `reason` — posting the general shape 422s.
  await waitFor(() => expect(mock.history.post).toHaveLength(1));
  expect(mock.history.post[0].url).toBe('/reviews/9/report');
  expect(JSON.parse(mock.history.post[0].data)).toEqual({ reason: 'off_topic' });
});

it('links out to the place reviews on Google when a google_place_id is present', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });

  render(<PlaceDetailScreen />, { wrapper: Providers });

  fireEvent.press(await screen.findByLabelText('Read all reviews on Google'));
  expect(Linking.openURL).toHaveBeenCalledWith(
    `https://search.google.com/local/reviews?placeid=${encodeURIComponent(PLACE.google_place_id!)}`,
  );
});

it('hides the Google reviews deep link when there is no google_place_id', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: { ...PLACE, google_place_id: null } });

  render(<PlaceDetailScreen />, { wrapper: Providers });

  await screen.findByText('1921 Restaurant');
  expect(screen.queryByLabelText('Read all reviews on Google')).toBeNull();
});

it('shows a hero image when the primary source has a thumbnail', async () => {
  const withHero: PlaceDetail = {
    ...PLACE,
    sources: [
      {
        ...PLACE.sources![0],
        source_post: { ...PLACE.sources![0].source_post, thumbnail_url: 'https://cdn.example/reel.jpg' },
      },
    ],
  };
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: withHero });

  render(<PlaceDetailScreen />, { wrapper: Providers });
  await screen.findByText('1921 Restaurant');
  expect(await screen.findByTestId('place-hero')).toBeOnTheScreen();
});

it('prefers the curated business image over the reel poster for the hero (T-084)', async () => {
  const withImage: PlaceDetail = {
    ...PLACE,
    image_url: 'https://cdn.example/business.jpg',
    sources: [
      {
        ...PLACE.sources![0],
        source_post: { ...PLACE.sources![0].source_post, thumbnail_url: 'https://cdn.example/reel.jpg' },
      },
    ],
  };
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: withImage });

  render(<PlaceDetailScreen />, { wrapper: Providers });
  await screen.findByText('1921 Restaurant');
  const hero = await screen.findByTestId('place-hero');
  expect(hero.props.source).toEqual({ uri: 'https://cdn.example/business.jpg' });
});

it('navigates to the map tab when the mini-map is tapped', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });

  render(<PlaceDetailScreen />, { wrapper: Providers });
  await screen.findByText('1921 Restaurant');

  fireEvent.press(screen.getByLabelText('Open in map'));
  expect(mockRouter.push).toHaveBeenCalledWith(
    expect.objectContaining({ pathname: '/(main)/map', params: { lat: '-34.890555', lng: '-56.055278' } }),
  );
});

it('renders card discount chips when the place has discounts', async () => {
  const withDiscounts: PlaceDetail = {
    ...PLACE,
    discounts: [
      { card: 'Santander', terms: '20% off', percent: 20 },
      { card: 'Visa', terms: '3 cuotas sin interés', percent: null },
    ],
  };
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: withDiscounts });

  render(<PlaceDetailScreen />, { wrapper: Providers });

  expect(await screen.findByText('Card discounts')).toBeOnTheScreen();
  expect(screen.getByText(/Santander · 20% off/)).toBeOnTheScreen();
  expect(screen.getByText(/Visa · 3 cuotas sin interés/)).toBeOnTheScreen();
});

it('omits the discounts section when there are none', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });

  render(<PlaceDetailScreen />, { wrapper: Providers });
  await screen.findByText('1921 Restaurant');
  expect(screen.queryByText('Card discounts')).toBeNull();
});

/*
 * The venue's live offers (T-047). A restaurant page is where someone decides
 * whether to go, so the reason to go belongs on it — the API has embedded these
 * since T-042 and the screen simply never asked for them.
 */
describe('the offers section', () => {
  const liveOffer = {
    id: '42',
    place_id: '1',
    title: 'Two-for-one pastéis',
    description: null,
    discount_type: 'percent' as const,
    discount_value: 20,
    terms: null,
    starts_at: '2020-01-01T00:00:00Z',
    ends_at: null,
    quota_total: null,
    quota_per_user: 1,
    quota_per_day: null,
    redemptions_count: 0,
    remaining_quota: null,
    status: 'active' as const,
    is_redeemable: true,
    created_at: null,
    updated_at: null,
  };

  it('asks the API to embed them — the section cannot render what was never fetched', async () => {
    mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });

    render(<PlaceDetailScreen />, { wrapper: Providers });
    await screen.findByText('1921 Restaurant');

    expect(mock.history.get[0].params.include).toContain('offers');
  });

  it('shows each live offer with a way to claim it', async () => {
    mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: { ...PLACE, offers: [liveOffer] } });

    render(<PlaceDetailScreen />, { wrapper: Providers });

    expect(await screen.findByTestId('place-offers')).toBeTruthy();
    expect(screen.getByText('Two-for-one pastéis')).toBeTruthy();
    expect(screen.getByText('20%')).toBeTruthy();

    fireEvent.press(screen.getByTestId('place-offer-cta-42'));
    expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/offers/[id]/redeem', params: { id: '42' } });
  });

  it('renders no section at all for a venue with nothing running', async () => {
    mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: { ...PLACE, offers: [] } });

    render(<PlaceDetailScreen />, { wrapper: Providers });
    await screen.findByText('1921 Restaurant');

    // Not an empty "no offers" block — absence of a promotion is not news.
    expect(screen.queryByTestId('place-offers')).toBeNull();
  });
});

describe('reporting (T-049)', () => {
  it('tells a signed-out visitor to sign in, rather than 401ing on submit', async () => {
    useSessionStore.setState({ user: null, status: 'guest' });
    mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });

    render(<PlaceDetailScreen />, { wrapper: Providers });
    await screen.findByText('1921 Restaurant');

    // The place page is public, so the control is reachable while signed out.
    fireEvent.press(screen.getByTestId('place-report'));

    expect(await screen.findByTestId('report-signed-out')).toBeTruthy();
    expect(screen.queryByTestId('report-submit')).toBeNull();
  });

  it('is reachable from the place page and files against the place', async () => {
    mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });
    mock.onPost('/reports').reply(201, { data: { report: { id: '1' } }, meta: {} });

    useSessionStore.setState({ user: null, status: 'authed' });

    render(<PlaceDetailScreen />, { wrapper: Providers });
    await screen.findByText('1921 Restaurant');

    // Apple 1.2 asks for a VISIBLE report path on user-generated content. A
    // sheet that only opens from a test bypasses the very thing being claimed,
    // so this presses the real control on the real screen.
    fireEvent.press(screen.getByTestId('place-report'));
    fireEvent.press(await screen.findByTestId('report-reason-wrong_place'));
    fireEvent.press(screen.getByTestId('report-submit'));

    await waitFor(() => expect(mock.history.post).toHaveLength(1));
    expect(JSON.parse(mock.history.post[0].data)).toMatchObject({
      reportable_type: 'place',
      reportable_id: PLACE.id,
      reason: 'wrong_place',
    });
  });
});

/**
 * Suggest an edit (T-083).
 *
 * Every case here goes through the REAL row on the REAL screen. A sheet
 * rendered directly in a test proves the sheet works and says nothing about
 * whether anyone can reach it — which is the failure mode this project keeps
 * shipping.
 */
describe('suggesting an edit', function () {
  it('is reachable from the place page and patches only what changed', async () => {
    mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });
    mock.onPost(`/places/${PLACE.slug}/suggestions`).reply(201, {
      data: {
        id: '7',
        place_id: PLACE.id,
        status: 'pending',
        is_owner_submission: false,
        changes: [{ field: 'phone', from: PLACE.phone, to: '+59829021622' }],
        created_at: '2026-08-12T10:00:00+00:00',
        reviewed_at: null,
      },
      meta: {},
    });
    useSessionStore.setState({ user: null, status: 'authed' });

    render(<PlaceDetailScreen />, { wrapper: Providers });
    await screen.findByText('1921 Restaurant');

    fireEvent.press(screen.getByTestId('place-suggest-edit'));
    fireEvent.changeText(await screen.findByTestId('suggest-phone'), '+59829021622');
    fireEvent.press(screen.getByTestId('suggest-submit'));

    await waitFor(() => expect(mock.history.post).toHaveLength(1));
    expect(JSON.parse(mock.history.post[0].data)).toEqual({ phone: '+59829021622' });
  });

  it('offers a guest nothing — the endpoint needs an account', async () => {
    mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });
    useSessionStore.setState({ user: null, status: 'guest' });

    render(<PlaceDetailScreen />, { wrapper: Providers });
    await screen.findByText('1921 Restaurant');

    expect(screen.queryByTestId('place-suggest-edit')).toBeNull();
  });

  it('words the row as an edit for the operator who may make one', async () => {
    mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: { ...PLACE, can_edit: true } });
    useSessionStore.setState({ user: null, status: 'authed' });

    render(<PlaceDetailScreen />, { wrapper: Providers });
    await screen.findByText('1921 Restaurant');

    // Same control, different promise. `can_edit` comes from the API, so a
    // revoked claim re-words this on the next fetch rather than at next launch.
    // By accessible NAME, not by text content: the label is what a screen
    // reader announces, and it has to agree with what the row says.
    expect(screen.getByLabelText('Edit business info')).toBeOnTheScreen();
    expect(screen.getByText('Edit business info')).toBeOnTheScreen();
  });

  it('words it as a suggestion for everyone else', async () => {
    mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });
    useSessionStore.setState({ user: null, status: 'authed' });

    render(<PlaceDetailScreen />, { wrapper: Providers });
    await screen.findByText('1921 Restaurant');

    expect(screen.getByLabelText('Something wrong? Suggest a change')).toBeOnTheScreen();
    expect(screen.getByText('Something wrong? Suggest a change')).toBeOnTheScreen();
  });
});
