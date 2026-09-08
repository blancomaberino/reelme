import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import InfluencerMapScreen from '../[id]/map';
import { api } from '@/api/client';

import { mockRouter } from '../../../jest.setup';

/**
 * The influencer's map (T-054 follow-up).
 *
 * This screen had no test at all, which is how it shipped drawing NOTHING for
 * every creator — it called the viewport endpoint with malformed params, 422'd,
 * and rendered its empty state over the failure. So the two things asserted
 * here are the two it got wrong: that it reads the LIST endpoint, and that what
 * it draws is the app's shared pin rather than the platform's default.
 */
function placeRow(id: string, name: string) {
  return {
    id, name, slug: `p-${id}`, status: 'active', lat: -34.9 + Number(id) * 0.01, lng: -56.16,
    category: null, price_range: null, city: 'Montevideo', country_code: 'UY', thumbnail_url: null,
    source_count: 1, rating: { google: { value: null, count: 0 } }, distance_m: null, open_state: null, created_at: null,
  };
}

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.params = { id: '7' };
  mock.onGet('/influencers/7').reply(200, {
    data: {
      id: '7', platform: 'instagram', handle: 'reviewer', display_name: 'The Reviewer',
      avatar_url: null, claimed: false, claimed_by: null, follower_count: 0,
      counters: { promoted_places: 2 },
    },
    meta: { viewer: { following: false, follow_id: null } },
  });
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('draws their places with the app’s shared pin', async () => {
  mock.onGet('/influencers/7/places').reply(200, {
    data: [placeRow('1', 'Clara Café'), placeRow('2', 'Manteigaria')],
  });

  render(<InfluencerMapScreen />, { wrapper: Providers });

  expect(await screen.findByTestId('MapView')).toBeOnTheScreen();
  expect(screen.getAllByTestId('Marker')).toHaveLength(2);
  // The names are the shared glyph's label — a bare <Marker> renders none.
  expect(screen.getByText('Clara Café')).toBeOnTheScreen();
});

it('says nothing rather than "no places" when the request fails', async () => {
  mock.onGet('/influencers/7/places').reply(500);

  render(<InfluencerMapScreen />, { wrapper: Providers });

  // The original bug in one assertion: a failed request rendered the empty
  // state, telling every visitor the creator had no places.
  expect(await screen.findByTestId('influencer-map-error')).toBeOnTheScreen();
  expect(screen.queryByTestId('influencer-map-empty')).toBeNull();
});

it('shows the empty state only when the list really is empty', async () => {
  mock.onGet('/influencers/7/places').reply(200, { data: [] });

  render(<InfluencerMapScreen />, { wrapper: Providers });

  expect(await screen.findByTestId('influencer-map-empty')).toBeOnTheScreen();
});
