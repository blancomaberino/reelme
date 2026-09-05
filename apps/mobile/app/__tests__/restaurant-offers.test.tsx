import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';
import { Alert } from 'react-native';

import RestaurantOffersScreen, { groupByVenue } from '../restaurant/offers';
import { api } from '@/api/client';
import type { Offer, Venue } from '@/api/offers';

/**
 * The operator's offer list (T-042).
 *
 * The rules pinned here are the ones a plausible screen gets wrong: showing a
 * pause button for something already stopped, ordering by recency so the live
 * offers sink below drafts, or leaving archived rows in a list nobody can act
 * on. Each is a case where the screen would look fine and mislead the person
 * deciding what their restaurant is currently promising.
 */

let mock: AxiosMockAdapter;
let qc: QueryClient;

const VENUE: Venue = {
  id: '10',
  name: 'Taberna do Bairro',
  slug: 'taberna-do-bairro',
  status: 'active',
  lat: 38.72,
  lng: -9.13,
  category: 'portuguese',
  price_range: 2,
  city: 'Lisboa',
  country_code: 'PT',
  source_count: 0,
  rating: { google: { value: null, count: 0 } },
  distance_m: null, open_state: null,
  created_at: null,
  thumbnail_url: null,
};

function offer(overrides: Partial<Offer> = {}): Offer {
  return {
    id: '1',
    place_id: '10',
    title: 'Two-for-one pastéis',
    description: null,
    discount_type: 'percent',
    discount_value: 20,
    terms: null,
    starts_at: '2020-01-01T00:00:00Z',
    ends_at: null,
    quota_total: null,
    quota_per_user: 1,
    quota_per_day: null,
    redemptions_count: 0,
    remaining_quota: null,
    status: 'active',
    is_redeemable: true,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

function serve(offers: Offer[], venues: Venue[] = [VENUE]) {
  mock.onGet('/me/venues').reply(200, { data: venues });
  mock.onGet('/offers').reply(200, { data: offers, meta: {} });
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: 0 } } });
  mock = new AxiosMockAdapter(api);
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

describe('the list', () => {
  it('renders the discount as its headline, in the right unit', async () => {
    serve([
      offer({ id: '1', discount_type: 'percent', discount_value: 25 }),
      // 350 minor units is €3.50 — shown raw it would read as a 350% discount.
      offer({ id: '2', discount_type: 'fixed_amount', discount_value: 350, title: 'Three fifty off' }),
    ]);

    render(<RestaurantOffersScreen />, { wrapper });

    expect(await screen.findByText('25%')).toBeTruthy();
    expect(screen.getByText('$3.50')).toBeTruthy();
  });

  it('offers a pause on a live offer and a publish on a draft', async () => {
    serve([offer({ id: '1' }), offer({ id: '2', status: 'draft', title: 'Not published yet' })]);

    render(<RestaurantOffersScreen />, { wrapper });
    await screen.findByText('Not published yet');

    expect(screen.getAllByText('Pause')).toHaveLength(1);
    expect(screen.getAllByText('Publish')).toHaveLength(1);
  });

  it('offers neither pause nor publish on an offer whose window has ended', async () => {
    // Still `status: active` — only the window says otherwise.
    serve([offer({ starts_at: '2020-01-01T00:00:00Z', ends_at: '2020-02-01T00:00:00Z' })]);

    render(<RestaurantOffersScreen />, { wrapper });
    await screen.findByText('20%');

    expect(screen.queryByText('Pause')).toBeNull();
    expect(screen.queryByText('Resume')).toBeNull();
    expect(screen.getByText('Ended')).toBeTruthy();
  });

  it('pauses an offer through the API', async () => {
    serve([offer()]);
    mock.onPatch('/offers/1').reply(200, { data: offer({ status: 'paused' }) });

    render(<RestaurantOffersScreen />, { wrapper });
    fireEvent.press(await screen.findByText('Pause'));

    await waitFor(() => {
      const patch = mock.history.patch[0];
      expect(patch).toBeDefined();
      expect(JSON.parse(patch.data)).toEqual({ status: 'paused' });
    });
  });

  it('publishes a draft rather than resuming it', async () => {
    serve([offer({ status: 'draft' })]);
    mock.onPatch('/offers/1').reply(200, { data: offer() });

    render(<RestaurantOffersScreen />, { wrapper });
    fireEvent.press(await screen.findByText('Publish'));

    await waitFor(() => expect(JSON.parse(mock.history.patch[0].data)).toEqual({ status: 'active' }));
  });

  it('resumes a paused offer', async () => {
    serve([offer({ status: 'paused' })]);
    mock.onPatch('/offers/1').reply(200, { data: offer() });

    render(<RestaurantOffersScreen />, { wrapper });
    fireEvent.press(await screen.findByText('Resume'));

    await waitFor(() => expect(JSON.parse(mock.history.patch[0].data)).toEqual({ status: 'active' }));
  });

  /*
   * Archiving is terminal and irreversible from the operator's side, so it must
   * go through a confirmation — a mis-tap on a row action would otherwise end a
   * running promotion for good.
   */
  it('confirms before archiving, and only then calls the API', async () => {
    serve([offer()]);
    mock.onDelete('/offers/1').reply(200, { data: offer({ status: 'archived' }) });
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);

    render(<RestaurantOffersScreen />, { wrapper });
    fireEvent.press(await screen.findByText('Archive'));

    expect(alert).toHaveBeenCalled();
    expect(mock.history.delete).toHaveLength(0);

    // Take the destructive button the alert offered and press it.
    const buttons = alert.mock.calls[0][2] as { style?: string; onPress?: () => void }[];
    buttons.find((b) => b.style === 'destructive')?.onPress?.();

    await waitFor(() => expect(mock.history.delete).toHaveLength(1));
    alert.mockRestore();
  });

  it('shows the quota meter against redemptions already taken', async () => {
    serve([offer({ quota_total: 100, redemptions_count: 40, remaining_quota: 60 })]);

    render(<RestaurantOffersScreen />, { wrapper });

    expect(await screen.findByText('40 of 100 redeemed')).toBeTruthy();
  });

  it('reads a sold-out offer as sold out, not as live', async () => {
    serve([offer({ quota_total: 10, redemptions_count: 10, remaining_quota: 0 })]);

    render(<RestaurantOffersScreen />, { wrapper });

    expect(await screen.findByText('Sold out')).toBeTruthy();
  });

  it('names a free-item offer in words, not as a bare number', async () => {
    serve([offer({ discount_type: 'free_item', discount_value: 1 })]);

    render(<RestaurantOffersScreen />, { wrapper });

    // "1" alone would read as a one-percent discount on the card's headline.
    expect(await screen.findByText('Free')).toBeTruthy();
  });

  it('asks the API only for the operator view, never the public browse', async () => {
    serve([offer()]);

    render(<RestaurantOffersScreen />, { wrapper });
    await screen.findByText('20%');

    const offersCall = mock.history.get.find((r) => r.url === '/offers');
    expect(offersCall?.params).toMatchObject({ mine: 1 });
  });

  it('sends the operator to the claim flow when they run no venue', async () => {
    serve([], []);

    render(<RestaurantOffersScreen />, { wrapper });

    expect(await screen.findByText('No verified restaurant')).toBeTruthy();
    // Nothing to create an offer for — the action must not be offered.
    expect(screen.queryByTestId('offers-new')).toBeNull();
  });

  it('prompts a venue owner with no offers to create their first', async () => {
    serve([]);

    render(<RestaurantOffersScreen />, { wrapper });

    expect(await screen.findByText('No offers yet')).toBeTruthy();
    expect(screen.getByTestId('offers-new')).toBeTruthy();
  });
});

describe('groupByVenue', () => {
  const NOW = new Date('2026-08-03T12:00:00Z');

  it('puts what diners can reach above what still needs a decision', () => {
    const rows = groupByVenue(
      [
        offer({ id: '1', status: 'draft' }),
        offer({ id: '2', starts_at: '2026-08-01T00:00:00Z', ends_at: '2026-09-01T00:00:00Z' }),
        offer({ id: '3', status: 'paused' }),
      ],
      NOW,
    );

    expect(rows[0][1].map((o) => o.id)).toEqual(['2', '1', '3']);
  });

  /*
   * Archived offers are terminal and the API never deletes them, so leaving
   * them in would give every operator a permanently growing tail of rows with
   * no available action.
   */
  it('drops archived offers entirely', () => {
    const rows = groupByVenue([offer({ id: '1', status: 'archived' }), offer({ id: '2' })], NOW);

    expect(rows[0][1].map((o) => o.id)).toEqual(['2']);
  });

  it('returns nothing when every offer is archived', () => {
    expect(groupByVenue([offer({ status: 'archived' })], NOW)).toEqual([]);
  });

  it('keeps each venue in its own group', () => {
    const rows = groupByVenue([offer({ id: '1', place_id: '10' }), offer({ id: '2', place_id: '11' })], NOW);

    expect(rows).toHaveLength(2);
    expect(rows.map(([placeId]) => placeId)).toEqual(['10', '11']);
  });

  it('breaks a tie within a rank by newest first', () => {
    const rows = groupByVenue([offer({ id: '7' }), offer({ id: '9' }), offer({ id: '8' })], NOW);

    expect(rows[0][1].map((o) => o.id)).toEqual(['9', '8', '7']);
  });
});
