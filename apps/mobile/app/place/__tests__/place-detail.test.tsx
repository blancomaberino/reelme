import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';
import { Linking, Share } from 'react-native';

import PlaceDetailScreen from '../[slug]';
import { api } from '@/api/client';
import type { PlaceDetail } from '@/api/places';

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
  cuisines: ['modern', 'seafood'],
  vibe_tags: ['fine dining'],
  dietary_tags: [],
  dishes: [
    { name: 'Ojo de bife', shown_in_video: false, price: '$780' },
    { name: 'Flan', shown_in_video: true, price: null },
  ],
  dishes_updated_at: '2026-07-10T12:00:00Z',
  source_count: 1,
  rating: { google: { value: 4.5, count: 527 }, app: { value: null, count: 0 } },
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
  // Google review name + stars share a Text node → match the name as a substring.
  expect(screen.getByText(/Ana Pérez/)).toBeOnTheScreen();
  expect(screen.getByText('Buena comida y atención.')).toBeOnTheScreen();
  // The photo-less Google review falls back to an initial avatar, not a crash.
  expect(screen.getByText(/Sin foto/)).toBeOnTheScreen();
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

it('navigates to the map tab when the mini-map is tapped', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(200, { data: PLACE });

  render(<PlaceDetailScreen />, { wrapper: Providers });
  await screen.findByText('1921 Restaurant');

  fireEvent.press(screen.getByLabelText('Open in map'));
  expect(mockRouter.push).toHaveBeenCalledWith(
    expect.objectContaining({ pathname: '/(main)/map', params: { lat: '-34.890555', lng: '-56.055278' } }),
  );
});
