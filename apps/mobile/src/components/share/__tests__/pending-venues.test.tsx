import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import { api } from '@/api/client';
import type { PendingVenue } from '@/api/shares';
import { PendingVenues } from '@/components/share/pending-venues';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

const venue: PendingVenue = {
  index: 2,
  name: 'Chiado',
  reason: 'ambiguous_place',
  candidates: [
    { place_id: '77', name: 'Chiado Restaurante', address: 'Bariloche', distance_m: 40, similarity: 0.9 },
    { place_id: '78', name: 'Chiado Café', address: 'Centro', distance_m: 120, similarity: 0.7 },
  ],
};

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('renders each pending venue and its candidates', () => {
  render(<PendingVenues shareId="6" venues={[venue]} />, { wrapper: Providers });

  expect(screen.getByText('Chiado')).toBeOnTheScreen();
  expect(screen.getByText('Chiado Restaurante')).toBeOnTheScreen();
  expect(screen.getByText('Chiado Café')).toBeOnTheScreen();
});

it('resolves the venue by picking a candidate (POST with its place_id + index)', async () => {
  let body: unknown = null;
  let url = '';
  mock.onPost(/\/shares\/6\/pending\/2\/resolve/).reply((cfg) => {
    body = JSON.parse(cfg.data);
    url = cfg.url ?? '';
    return [200, { data: {} }];
  });

  render(<PendingVenues shareId="6" venues={[venue]} />, { wrapper: Providers });
  fireEvent.press(screen.getByLabelText('Chiado Restaurante'));

  await waitFor(() => expect(body).toEqual({ place_id: 77 }));
  expect(url).toContain('/shares/6/pending/2/resolve');
});

it('dismisses the venue (DELETE /shares/{id}/pending/{index})', async () => {
  let deleted = '';
  mock.onDelete('/shares/6/pending/2').reply((cfg) => {
    deleted = cfg.url ?? '';
    return [200, { data: {} }];
  });

  render(<PendingVenues shareId="6" venues={[venue]} />, { wrapper: Providers });
  fireEvent.press(screen.getByLabelText('Dismiss'));

  await waitFor(() => expect(deleted).toBe('/shares/6/pending/2'));
});

it('shows a no-matches note when a venue has no candidates', () => {
  render(<PendingVenues shareId="6" venues={[{ ...venue, candidates: [] }]} />, { wrapper: Providers });
  expect(screen.getByText(/No matches found/)).toBeOnTheScreen();
});
