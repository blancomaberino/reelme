import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import { api } from '@/api/client';
import type { PlaceSummary } from '@/api/places';
import { AddPlaceToListSheet } from '@/components/place/add-to-list-search';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

function place(id: string, name: string): PlaceSummary {
  return {
    id,
    name,
    slug: `${name.toLowerCase()}-${id}`,
    status: 'active',
    lat: -34.9,
    lng: -56.16,
    category: 'modern',
    price_range: 2,
    city: 'Montevideo',
    country_code: 'UY',
    source_count: 1,
    rating: { google: { value: null, count: 0 } },
    distance_m: null,
    created_at: null,
  };
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('searches and adds a non-member place, marking members as saved', async () => {
  mock.onGet('/search').reply(200, {
    data: { places: [place('7', 'Nuevo'), place('9', 'YaEsta')], tags: [], influencers: [] },
    meta: { query: 'mont', took_ms: 1 },
  });
  let addedPlace: string | null = null;
  mock.onPost(/\/me\/lists\/3\/places\/7/).reply(() => {
    addedPlace = '7';
    return [201, { data: {} }];
  });

  render(
    <AddPlaceToListSheet visible onClose={() => {}} listId="3" memberIds={new Set(['9'])} />,
    { wrapper: Providers },
  );

  fireEvent.changeText(screen.getByLabelText('Search'), 'mont');

  // Both results render; the already-member one shows "Saved".
  expect(await screen.findByText('Nuevo')).toBeOnTheScreen();
  expect(screen.getByText('Saved')).toBeOnTheScreen();

  // Tapping the non-member place adds it to the list.
  fireEvent.press(screen.getByLabelText('Nuevo'));
  await waitFor(() => expect(addedPlace).toBe('7'));
});

it('does not add a place that is already a member', async () => {
  mock.onGet('/search').reply(200, {
    data: { places: [place('9', 'YaEsta')], tags: [], influencers: [] },
    meta: { query: 'ya', took_ms: 1 },
  });
  let posted = false;
  mock.onPost(/\/me\/lists\/3\/places\/9/).reply(() => {
    posted = true;
    return [201, { data: {} }];
  });

  render(
    <AddPlaceToListSheet visible onClose={() => {}} listId="3" memberIds={new Set(['9'])} />,
    { wrapper: Providers },
  );

  fireEvent.changeText(screen.getByLabelText('Search'), 'yaesta');
  fireEvent.press(await screen.findByLabelText('YaEsta'));

  // Give any (erroneous) request a tick to fire — it must not.
  await new Promise((r) => setTimeout(r, 50));
  expect(posted).toBe(false);
});
