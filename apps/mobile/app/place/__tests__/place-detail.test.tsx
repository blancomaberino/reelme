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
  dishes: [{ name: 'Ojo de bife', shown_in_video: false }],
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
  expect(screen.getByText(/modern · €€€/)).toBeOnTheScreen();
  expect(screen.getByText(/4\.5/)).toBeOnTheScreen();
  expect(screen.getByText(/Rbla\. República/)).toBeOnTheScreen();
  // Tag chip from cuisines/vibe_tags union.
  expect(screen.getByText('fine dining')).toBeOnTheScreen();
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

it('shows the not-found state on a 404', async () => {
  mock.onGet(`/places/${PLACE.slug}`).reply(404, { error: { message: 'not found' } });

  render(<PlaceDetailScreen />, { wrapper: Providers });

  expect(await screen.findByText('Place not found')).toBeOnTheScreen();
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
