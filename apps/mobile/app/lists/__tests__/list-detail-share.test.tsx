import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, fireEvent, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';
import { Share } from 'react-native';

import ListDetailScreen from '../[id]';
import { api } from '@/api/client';
import type { PlaceListDetail } from '@/api/lists';
import type { PlaceSummary } from '@/api/places';

import { mockRouter } from '../../../jest.setup';

let mock: AxiosMockAdapter;
let qc: QueryClient;
let shareSpy: jest.SpyInstance;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

function place(id: string, name: string): PlaceSummary {
  return {
    id, name, slug: `${name.toLowerCase()}-${id}`, status: 'active', lat: -34.9, lng: -56.16,
    category: 'modern', price_range: 2, city: 'Montevideo', country_code: 'UY', source_count: 1,
    rating: { google: { value: null, count: 0 } }, distance_m: null, created_at: null,
  };
}

function listDetail(over: Partial<PlaceListDetail>): PlaceListDetail {
  return {
    id: '5', name: 'Lisbon food', slug: 'lisbon-food', public_slug: null, is_public: false,
    items_count: 1, items: [{ note: null, position: 1, place: place('7', 'Clara') }],
    created_at: null, updated_at: null, ...over,
  };
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.params = { id: '5' };
  shareSpy = jest.spyOn(Share, 'share').mockResolvedValue({ action: 'sharedAction' } as never);
});
afterEach(() => {
  mock.restore();
  qc.clear();
  shareSpy.mockRestore();
});

it('opens the OS share sheet directly for an already-public list', async () => {
  mock.onGet('/me/lists/5').reply(200, { data: listDetail({ is_public: true, public_slug: 'lisbon-food-x7k2ab' }) });

  render(<ListDetailScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByLabelText('Share list'));

  await waitFor(() => expect(shareSpy).toHaveBeenCalled());
  expect(shareSpy.mock.calls[0][0]).toMatchObject({ url: 'reelmap://list/lisbon-food-x7k2ab' });
  // No PATCH — it was already public.
  expect(mock.history.patch).toHaveLength(0);
});

it('publishes a private list first, then shares the minted link', async () => {
  mock.onGet('/me/lists/5').reply(200, { data: listDetail({ is_public: false, public_slug: null }) });
  mock.onPatch('/me/lists/5').reply(200, { data: listDetail({ is_public: true, public_slug: 'lisbon-food-newpub' }) });

  render(<ListDetailScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByLabelText('Share list'));

  await waitFor(() => expect(mock.history.patch).toHaveLength(1));
  expect(JSON.parse(mock.history.patch[0].data)).toMatchObject({ is_public: true });
  await waitFor(() => expect(shareSpy).toHaveBeenCalled());
  expect(shareSpy.mock.calls[0][0]).toMatchObject({ url: 'reelmap://list/lisbon-food-newpub' });
});
