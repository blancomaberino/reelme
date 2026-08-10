import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';
import { Linking, Share } from 'react-native';

import PlaceDetailScreen from '../[slug]';
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

it('renders an open/closed hours summary when hours are present', async () => {
  const withHours = {
    ...PLACE,
    // Open every day 00:00–23:59 → always "Open now" regardless of test clock.
    opening_hours: {
      periods: [0, 1, 2, 3, 4, 5, 6].map((day) => ({
        open: { day, time: '0000' },
        close: { day, time: '2359' },
      })),
    },
  };
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: withHours });

  render(<PlaceDetailScreen />, { wrapper: Providers });
  expect(await screen.findByText(/Open now/)).toBeOnTheScreen();
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
        author: { username: 'foodie', avatar_path: null },
        is_own: false,
        created_at: '2026-07-01T00:00:00Z',
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
        author: { username: 'foodie', avatar_path: null },
        is_own: false,
        created_at: '2026-07-01T00:00:00Z',
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
