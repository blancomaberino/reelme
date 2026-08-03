import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import VerifyScreen from '../verify';
import { api } from '@/api/client';
import type { Venue } from '@/api/offers';
import type { Redemption } from '@/api/redemptions';

/**
 * The till (T-047).
 *
 * Two properties matter here and both are about someone standing at a counter
 * with a queue behind them: one scan means ONE verify request, and every
 * outcome says what to do next in those terms. A camera fires the same barcode
 * many times a second, so without the lock a single customer trips the
 * staff-velocity limiter that exists to catch code-guessing.
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
  distance_m: null,
  created_at: null,
  thumbnail_url: null,
};

const REDEEMED: Redemption = {
  id: '55',
  offer_id: '7',
  status: 'redeemed',
  is_live: false,
  issued_at: '2026-08-03T11:45:00Z',
  expires_at: '2026-08-03T12:15:00Z',
  redeemed_at: '2026-08-03T12:00:00Z',
  attribution: { influencer_id: '3', share_id: '9' },
};

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

function serveVenues(venues: Venue[] = [VENUE]) {
  mock.onGet('/me/venues').reply(200, { data: venues });
}

beforeEach(() => {
  mock = new AxiosMockAdapter(api);
  qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

describe('verifying a code', () => {
  it('accepts a typed code and shows the offer staff must honour', async () => {
    serveVenues();
    mock.onPost('/redemptions/verify').reply(200, {
      data: { ...REDEEMED, offer: { title: 'Two-for-one pastéis', terms: 'Dine-in only' } },
      meta: { replayed: false },
    });

    render(<VerifyScreen />, { wrapper });
    await waitFor(() => expect(screen.getByTestId('verify-manual-input')).toBeTruthy());

    fireEvent.changeText(screen.getByTestId('verify-manual-input'), '7f3k-92qx-ab');
    fireEvent.press(screen.getByTestId('verify-submit'));

    await waitFor(() => expect(screen.getByTestId('verify-result-success')).toBeTruthy());
    // The terms, not just a green tick — staff have to know WHAT to give away.
    expect(screen.getByText('Two-for-one pastéis')).toBeTruthy();
    expect(screen.getByText('Dine-in only')).toBeTruthy();
  });

  it('sends the code to the venue the operator runs', async () => {
    serveVenues();
    mock.onPost('/redemptions/verify').reply(200, { data: REDEEMED, meta: { replayed: false } });

    render(<VerifyScreen />, { wrapper });
    await waitFor(() => expect(screen.getByTestId('verify-manual-input')).toBeTruthy());

    fireEvent.changeText(screen.getByTestId('verify-manual-input'), '7F3K92QXAB');
    fireEvent.press(screen.getByTestId('verify-submit'));

    await waitFor(() => expect(mock.history.post).toHaveLength(1));
    expect(JSON.parse(mock.history.post[0].data)).toMatchObject({ code: '7F3K92QXAB', place_id: 10 });
  });

  /*
   * A replay is a SUCCESS with a note. The API returns 200 with the original
   * result, and rendering it red would have staff turning away a customer whose
   * code was already scanned — the exact failure the exactly-once server design
   * exists to prevent.
   */
  it('renders an already-scanned code as a success, not a rejection', async () => {
    serveVenues();
    mock.onPost('/redemptions/verify').reply(200, { data: REDEEMED, meta: { replayed: true } });

    render(<VerifyScreen />, { wrapper });
    await waitFor(() => expect(screen.getByTestId('verify-manual-input')).toBeTruthy());

    fireEvent.changeText(screen.getByTestId('verify-manual-input'), '7F3K92QXAB');
    fireEvent.press(screen.getByTestId('verify-submit'));

    await waitFor(() => expect(screen.getByTestId('verify-result-success')).toBeTruthy());
    expect(screen.queryByTestId('verify-result-failure')).toBeNull();
    expect(screen.getByText('Already verified')).toBeTruthy();
  });

  it.each([
    [404, 'not_found', 'Not a valid code'],
    [422, 'expired', 'This code expired'],
    [422, 'wrong_place', 'Wrong restaurant'],
    [422, 'outside_geofence', 'Too far from the restaurant'],
    [429, 'staff_velocity_exceeded', 'Too many checks'],
  ])('tells staff what a %i %s means in their own terms', async (status, reason, title) => {
    serveVenues();
    mock.onPost('/redemptions/verify').reply(status, { error: { details: { reason } } });

    render(<VerifyScreen />, { wrapper });
    await waitFor(() => expect(screen.getByTestId('verify-manual-input')).toBeTruthy());

    fireEvent.changeText(screen.getByTestId('verify-manual-input'), 'BADCODE123');
    fireEvent.press(screen.getByTestId('verify-submit'));

    await waitFor(() => expect(screen.getByTestId('verify-result-failure')).toBeTruthy());
    expect(screen.getByText(title)).toBeTruthy();
  });

  it('shows the generic failure for a reason the app has no copy for', async () => {
    serveVenues();
    mock.onPost('/redemptions/verify').reply(422, { error: { details: { reason: 'invented_by_the_server' } } });

    render(<VerifyScreen />, { wrapper });
    await waitFor(() => expect(screen.getByTestId('verify-manual-input')).toBeTruthy());

    fireEvent.changeText(screen.getByTestId('verify-manual-input'), 'BADCODE123');
    fireEvent.press(screen.getByTestId('verify-submit'));

    // Not an empty sheet whose heading is a string the server invented.
    await waitFor(() => expect(screen.getByTestId('verify-result-failure')).toBeTruthy());
    expect(screen.getByText('Could not check that code')).toBeTruthy();
  });
});

describe('the scanner lock', () => {
  it('sends exactly one request when the camera fires the same code repeatedly', async () => {
    serveVenues();
    mock.onPost('/redemptions/verify').reply(200, { data: REDEEMED, meta: { replayed: false } });

    render(<VerifyScreen />, { wrapper });
    const camera = await screen.findByTestId('verify-camera');

    // What a real barcode scanner does: the same payload, many times, well
    // inside one React render cycle. A state flag would let the second through.
    fireEvent(camera, 'barcodeScanned', { data: 'v1.7F3K92QXAB.sig' });
    fireEvent(camera, 'barcodeScanned', { data: 'v1.7F3K92QXAB.sig' });
    fireEvent(camera, 'barcodeScanned', { data: 'v1.7F3K92QXAB.sig' });

    await waitFor(() => expect(screen.getByTestId('verify-result-success')).toBeTruthy());
    expect(mock.history.post).toHaveLength(1);
  });

  it('unlocks for the next customer once the result is dismissed', async () => {
    serveVenues();
    mock.onPost('/redemptions/verify').reply(200, { data: REDEEMED, meta: { replayed: false } });

    render(<VerifyScreen />, { wrapper });
    const camera = await screen.findByTestId('verify-camera');
    fireEvent(camera, 'barcodeScanned', { data: 'v1.7F3K92QXAB.sig' });

    await waitFor(() => expect(screen.getByTestId('verify-next')).toBeTruthy());
    fireEvent.press(screen.getByTestId('verify-next'));

    const next = await screen.findByTestId('verify-camera');
    fireEvent(next, 'barcodeScanned', { data: 'v1.AAAA1111BB.sig' });

    await waitFor(() => expect(mock.history.post).toHaveLength(2));
  });
});

describe('when the account runs no venue', () => {
  it('explains instead of offering a scanner that cannot resolve a place', async () => {
    serveVenues([]);

    render(<VerifyScreen />, { wrapper });

    await waitFor(() => expect(screen.getByText(/Claim your restaurant first/)).toBeTruthy());
    expect(screen.queryByTestId('verify-camera')).toBeNull();
  });
});
