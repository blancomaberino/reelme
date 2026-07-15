import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import UserMapScreen from '../[username]/map';
import { api } from '@/api/client';

import { mockRouter } from '../../../jest.setup';

function placeRow(id: string, name: string) {
  return {
    id, name, slug: `p-${id}`, status: 'active', lat: -34.9 + Number(id) * 0.01, lng: -56.16, category: null,
    price_range: null, city: 'Montevideo', country_code: 'UY', thumbnail_url: null,
    source_count: 1, rating: { google: { value: null, count: 0 } }, distance_m: null, created_at: null,
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
  mockRouter.params = { username: 'alice' };
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('renders their places as markers on a fit-to-bounds map', async () => {
  mock.onGet('/users/alice/places').reply(200, { data: [placeRow('1', 'Clara Café'), placeRow('2', 'Manteigaria')] });

  render(<UserMapScreen />, { wrapper: Providers });

  expect(await screen.findByTestId('MapView')).toBeOnTheScreen();
  expect(screen.getAllByTestId('Marker')).toHaveLength(2);
});

it('shows an empty state (no map) when the user has no places', async () => {
  mock.onGet('/users/alice/places').reply(200, { data: [] });

  render(<UserMapScreen />, { wrapper: Providers });

  expect(await screen.findByText('No places yet.')).toBeOnTheScreen();
  expect(screen.queryByTestId('MapView')).toBeNull();
});
