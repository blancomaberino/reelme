import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import RestaurantSuggestionsScreen from '../restaurant/suggestions';
import { api } from '@/api/client';
import type { PlaceEditSuggestion } from '@/api/suggestions';

import { mockRouter } from '../../jest.setup';

/**
 * What people are proposing about the venues you run (T-083).
 *
 * Read-only by design — a venue that could approve edits to its own listing
 * could also silently refuse every correction to it. So the assertions here are
 * about what the operator can SEE (which venue, which field, from what to what)
 * and about the absence of any decision control.
 */
let qc: QueryClient;
let mock: AxiosMockAdapter;

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

const SUGGESTION: PlaceEditSuggestion = {
  id: '3',
  place_id: '11',
  place: { id: '11', name: 'Cantina Vieja', slug: 'cantina-vieja-abc123' },
  status: 'pending',
  is_owner_submission: false,
  changes: [
    { field: 'phone', from: '+598 2 111 1111', to: '+598 2 900 0000' },
    { field: 'website', from: null, to: 'https://cantina.uy' },
  ],
  created_at: '2026-08-12T10:00:00+00:00',
  reviewed_at: null,
};

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.push.mockClear();
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

it('shows the venue and each proposed field, from what to what', async () => {
  mock.onGet('/me/venues/suggestions').reply(200, { data: [SUGGESTION], meta: {} });

  render(<RestaurantSuggestionsScreen />, { wrapper });

  expect(await screen.findByText('Cantina Vieja')).toBeOnTheScreen();
  // Field labels, not raw column names — "address_line1" at a restaurant owner
  // is the API leaking through the screen.
  expect(screen.getByText('Phone')).toBeOnTheScreen();
  expect(screen.getByText('Website')).toBeOnTheScreen();
  expect(screen.getByText('+598 2 111 1111')).toBeOnTheScreen();
  expect(screen.getByText('+598 2 900 0000')).toBeOnTheScreen();
  // A field that was empty reads as empty, not as a blank gap where the
  // before-value should be.
  expect(screen.getByText('(empty)')).toBeOnTheScreen();
  expect(screen.getByText('https://cantina.uy')).toBeOnTheScreen();
});

it('renders an array value as a line rather than "[object Object]"', async () => {
  mock.onGet('/me/venues/suggestions').reply(200, {
    data: [
      {
        ...SUGGESTION,
        changes: [{ field: 'opening_hours_json', from: null, to: ['Lu-Vi 12:00–15:00', 'Sa 20:00–23:30'] }],
      },
    ],
    meta: {},
  });

  render(<RestaurantSuggestionsScreen />, { wrapper });

  expect(await screen.findByText('Lu-Vi 12:00–15:00 · Sa 20:00–23:30')).toBeOnTheScreen();
});

it('offers no approve or reject — a moderator decides these', async () => {
  mock.onGet('/me/venues/suggestions').reply(200, { data: [SUGGESTION], meta: {} });

  render(<RestaurantSuggestionsScreen />, { wrapper });
  await screen.findByText('Cantina Vieja');

  expect(screen.getByText('Awaiting review')).toBeOnTheScreen();

  // STRUCTURAL, not `queryByText('Approve')`: the app has no such copy, so
  // asserting on those literals would pass whether or not a decision control
  // exists — a real one would be labelled from the dictionary and slip
  // straight past. The only button on this screen is the venue's own name.
  const names = screen
    .getAllByRole('button')
    .map((node) => node.props.accessibilityLabel);
  expect(names).toEqual(['Go back', 'Cantina Vieja']);
});

it('taps the venue through to its page, so a proposal can be checked against it', async () => {
  mock.onGet('/me/venues/suggestions').reply(200, { data: [SUGGESTION], meta: {} });

  render(<RestaurantSuggestionsScreen />, { wrapper });
  fireEvent.press(await screen.findByText('Cantina Vieja'));

  expect(mockRouter.push).toHaveBeenCalledWith({
    pathname: '/place/[slug]',
    params: { slug: 'cantina-vieja-abc123' },
  });
});

it('says there is nothing pending rather than showing an empty page', async () => {
  mock.onGet('/me/venues/suggestions').reply(200, { data: [], meta: {} });

  render(<RestaurantSuggestionsScreen />, { wrapper });

  expect(await screen.findByText('Nothing pending')).toBeOnTheScreen();
});

it('offers a retry when the list fails to load', async () => {
  mock.onGet('/me/venues/suggestions').reply(500);

  render(<RestaurantSuggestionsScreen />, { wrapper });

  expect(await screen.findByText('Try again')).toBeOnTheScreen();
});
