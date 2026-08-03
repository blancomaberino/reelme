import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import OffersBrowseScreen from '../index';
import { api } from '@/api/client';
import type { Offer } from '@/api/offers';
import { mockRouter } from '@/../jest.setup';
import * as initialRegion from '@/lib/initial-region';

/**
 * Nearby offers (T-047).
 *
 * The browse is only honest if two things hold: it never lists an offer whose
 * window has closed, and it never pretends to know where the diner is. The
 * first is `active=1` on the request — `status` alone still reads `active` for
 * a promotion that ended overnight. The second is why a refused permission gets
 * its own state rather than an empty list from a default city centre.
 */
let mock: AxiosMockAdapter;
let qc: QueryClient;
let locate: jest.SpyInstance;

const REGION = { latitude: 38.7223, longitude: -9.1393, latitudeDelta: 0.02, longitudeDelta: 0.02 };

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
    place: {
      id: '10',
      name: 'Taberna do Bairro',
      slug: 'taberna-do-bairro',
      city: 'Lisboa',
      country_code: 'PT',
      thumbnail_url: null,
      lat: 38.72,
      lng: -9.13,
    },
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  mock = new AxiosMockAdapter(api);
  qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  locate = jest.spyOn(initialRegion, 'locateUser').mockResolvedValue({ ok: true, region: REGION });
  mockRouter.push.mockClear();
});

afterEach(() => {
  mock.restore();
  qc.clear();
  locate.mockRestore();
});

describe('the list', () => {
  it('asks only for offers redeemable right now, near the diner', async () => {
    mock.onGet('/offers').reply(200, { data: [offer()] });

    render(<OffersBrowseScreen />, { wrapper });

    await waitFor(() => expect(mock.history.get).toHaveLength(1));
    // Without `active=1` this lists offers whose window shut overnight — the
    // column never gets rewritten — and sends someone to a closed promotion.
    expect(mock.history.get[0].params).toMatchObject({ active: 1, near: '38.7223,-9.1393' });
  });

  it('shows the venue behind each offer, not just the discount', async () => {
    mock.onGet('/offers').reply(200, { data: [offer()] });

    render(<OffersBrowseScreen />, { wrapper });

    await waitFor(() => expect(screen.getByText('Two-for-one pastéis')).toBeTruthy());
    expect(screen.getByText(/Taberna do Bairro/)).toBeTruthy();
  });

  it('says there is nothing nearby rather than showing a blank screen', async () => {
    mock.onGet('/offers').reply(200, { data: [] });

    render(<OffersBrowseScreen />, { wrapper });

    await waitFor(() => expect(screen.getByTestId('offers-empty')).toBeTruthy());
  });

  it('routes to the code screen for the offer that was tapped', async () => {
    mock.onGet('/offers').reply(200, { data: [offer({ id: '42' })] });

    render(<OffersBrowseScreen />, { wrapper });
    await waitFor(() => expect(screen.getByText('Two-for-one pastéis')).toBeTruthy());

    fireEvent.press(screen.getByText('Get code'));

    expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/offers/[id]/redeem', params: { id: '42' } });
  });
});

describe('without a location fix', () => {
  it('asks for permission instead of listing offers from nowhere', async () => {
    locate.mockResolvedValue({ ok: false, reason: 'denied' });

    render(<OffersBrowseScreen />, { wrapper });

    await waitFor(() => expect(screen.getByTestId('offers-location-blocked')).toBeTruthy());
    // Critically: no request went out. A browse with no fix has nothing to ask.
    expect(mock.history.get).toHaveLength(0);
  });

  it('sends a hard-denied user to Settings, which is the only thing that fixes it', async () => {
    locate.mockResolvedValue({ ok: false, reason: 'blocked' });

    render(<OffersBrowseScreen />, { wrapper });

    await waitFor(() => expect(screen.getByText('Open settings')).toBeTruthy());
  });

  it('retries the fix when the refusal was only a dismissed prompt', async () => {
    locate.mockResolvedValueOnce({ ok: false, reason: 'denied' });

    render(<OffersBrowseScreen />, { wrapper });
    await waitFor(() => expect(screen.getByTestId('offers-location-cta')).toBeTruthy());

    locate.mockResolvedValue({ ok: true, region: REGION });
    mock.onGet('/offers').reply(200, { data: [offer()] });
    fireEvent.press(screen.getByTestId('offers-location-cta'));

    await waitFor(() => expect(screen.getByText('Two-for-one pastéis')).toBeTruthy());
  });
});

describe('when the request fails', () => {
  it('offers a retry rather than an empty list that looks like "nothing nearby"', async () => {
    mock.onGet('/offers').reply(500);

    render(<OffersBrowseScreen />, { wrapper });

    await waitFor(() => expect(screen.getByText('Something went wrong. Please try again.')).toBeTruthy());
    // Critically NOT the empty state — "no offers here" and "we could not ask"
    // are different facts, and the first one sends the diner somewhere else.
    expect(screen.queryByTestId('offers-empty')).toBeNull();

    mock.onGet('/offers').reply(200, { data: [offer()] });
    fireEvent.press(screen.getByText('Try again'));

    await waitFor(() => expect(screen.getByText('Two-for-one pastéis')).toBeTruthy());
  });
});

describe('the map toggle', () => {
  /*
   * The offers map is not a second, lesser map. It carries the same control
   * stack as the home map — locate, reset, zoom — because a user who learns
   * those on one screen should not find them missing on the other.
   */
  it('carries the same controls as the home map', async () => {
    mock.onGet('/offers').reply(200, { data: [offer()] });

    render(<OffersBrowseScreen />, { wrapper });
    await waitFor(() => expect(screen.getByText('Two-for-one pastéis')).toBeTruthy());

    fireEvent.press(screen.getByTestId('offers-toggle-map'));

    await waitFor(() => expect(screen.getByTestId('map-locate')).toBeTruthy());
    expect(screen.getByTestId('map-reset')).toBeTruthy();
    expect(screen.getByTestId('map-zoom-in')).toBeTruthy();
    expect(screen.getByTestId('map-zoom-out')).toBeTruthy();
  });

  /*
   * The map draws from a fallback region, so it must render even with no fix —
   * the control stack's "locate me" is a better answer to a missing position
   * than a screen with nothing on it. It used to render `null`, which is what
   * made it look broken next to the real map.
   */
  it('still draws the map when the device has no location fix', async () => {
    locate.mockResolvedValue({ ok: false, reason: 'unavailable' });

    render(<OffersBrowseScreen />, { wrapper });
    await waitFor(() => expect(screen.getByTestId('offers-toggle-map')).toBeTruthy());

    fireEvent.press(screen.getByTestId('offers-toggle-map'));

    await waitFor(() => expect(screen.getByTestId('map-locate')).toBeTruthy());
    // Not the list's "turn on location" dead end.
    expect(screen.queryByTestId('offers-location-blocked')).toBeNull();
  });

  /*
   * The bug this pins: the map used to be nailed to the DEVICE fix, so panning
   * to a restaurant 19km away asked the API about your own sofa and answered
   * with an empty map however far you dragged. A map is a question about where
   * you are LOOKING.
   */
  it('re-asks the API for the region the user panned to', async () => {
    mock.onGet('/offers').reply(200, { data: [] });

    render(<OffersBrowseScreen />, { wrapper });
    await waitFor(() => expect(screen.getByTestId('offers-toggle-map')).toBeTruthy());
    fireEvent.press(screen.getByTestId('offers-toggle-map'));

    await waitFor(() => expect(mock.history.get.length).toBeGreaterThan(0));
    const before = mock.history.get.length;

    // Drag to the other venue, ~19km away and far outside the 2km list radius.
    fireEvent(screen.getByTestId('offers-map'), 'regionChangeComplete', {
      latitude: -34.8436,
      longitude: -55.9693,
      latitudeDelta: 0.02,
      longitudeDelta: 0.02,
    });

    await waitFor(() => expect(mock.history.get.length).toBeGreaterThan(before));
    const asked = mock.history.get[mock.history.get.length - 1].params;
    expect(asked.near).toBe('-34.8436,-55.9693');
    // And it asks for the whole viewport, not the list's fixed 2km.
    expect(asked.radius_m).toBeGreaterThan(1_000);
  });

  it('keeps the list on the diner, not on wherever the map was dragged', async () => {
    mock.onGet('/offers').reply(200, { data: [] });

    render(<OffersBrowseScreen />, { wrapper });
    await waitFor(() => expect(mock.history.get.length).toBeGreaterThan(0));

    // "Offers near you" is a list of places you could walk to — it must stay
    // anchored to the device even after the map has been moved elsewhere.
    expect(mock.history.get[0].params).toMatchObject({ near: '38.7223,-9.1393', radius_m: 2000 });
  });

  it('places a marker per offer once the diner switches to the map', async () => {
    mock.onGet('/offers').reply(200, { data: [offer(), offer({ id: '2', place_id: '11' })] });

    render(<OffersBrowseScreen />, { wrapper });
    await waitFor(() => expect(screen.getAllByText('Two-for-one pastéis')).toHaveLength(2));

    fireEvent.press(screen.getByTestId('offers-toggle-map'));

    // The discount, not a generic pin — the number is the reason to walk there.
    await waitFor(() => expect(screen.getAllByText('20%')).toHaveLength(2));
  });

  /*
   * "3" on a pin over a restaurant is not a shorter way of saying "€3 off" —
   * it is a number with no promise attached. A pin may abbreviate; it may not
   * misstate what the offer is.
   */
  it.each([
    // NOT "$4" — abbreviating a discount upward promises more than the offer
    // gives, and the diner only finds out at the counter.
    [{ discount_type: 'fixed_amount' as const, discount_value: 350 }, '$3.50'],
    [{ discount_type: 'free_item' as const, discount_value: 2 }, '×2'],
  ])('keeps the unit on the marker for %o', async (discount, label) => {
    mock.onGet('/offers').reply(200, { data: [offer(discount)] });

    render(<OffersBrowseScreen />, { wrapper });
    await waitFor(() => expect(screen.getByText('Two-for-one pastéis')).toBeTruthy());

    fireEvent.press(screen.getByTestId('offers-toggle-map'));

    await waitFor(() => expect(screen.getByText(label)).toBeTruthy());
  });

  it('opens the offer card when a marker is tapped', async () => {
    mock.onGet('/offers').reply(200, { data: [offer({ id: '42' })] });

    render(<OffersBrowseScreen />, { wrapper });
    await waitFor(() => expect(screen.getByText('Two-for-one pastéis')).toBeTruthy());
    fireEvent.press(screen.getByTestId('offers-toggle-map'));

    fireEvent.press(await screen.findByText('20%'));

    // The pin carries the number; the sheet carries what it is and how to take
    // it — a marker alone cannot say "Dine-in only".
    await waitFor(() => expect(screen.getByTestId('offers-map-sheet')).toBeTruthy());
    fireEvent.press(screen.getByText('Get code'));
    expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/offers/[id]/redeem', params: { id: '42' } });
  });

  /*
   * A venue with no coordinates cannot be a marker. Silently dropping it makes
   * the map quietly disagree with the list the diner was just looking at.
   */
  it('says so when an offer cannot be placed on the map', async () => {
    mock.onGet('/offers').reply(200, {
      data: [offer(), offer({ id: '2', place: { ...offer().place!, lat: null, lng: null } })],
    });

    render(<OffersBrowseScreen />, { wrapper });
    await waitFor(() => expect(screen.getAllByText('Two-for-one pastéis')).toHaveLength(2));

    fireEvent.press(screen.getByTestId('offers-toggle-map'));

    await waitFor(() => expect(screen.getByText('1 offer has no map location.')).toBeTruthy());
  });
});
