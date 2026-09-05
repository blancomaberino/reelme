import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import OfferFormScreen, { toDiscountValue, toNullableInt } from '../restaurant/offer';
import { api } from '@/api/client';
import type { Venue } from '@/api/offers';
import { mockRouter } from '../../jest.setup';

/**
 * The create/edit offer form (T-042).
 *
 * The property under test: **the number the operator types is not the number
 * the API stores.** `discount_value` is one integer in three units, and the one
 * that hurts is `fixed_amount` — "3.50" must reach the wire as 350 minor units.
 * A form that sends 3.5, or 350 for "350", either underpays the restaurant by a
 * factor of 100 or promises a discount nobody can honour.
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

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: 0 } } });
  mock = new AxiosMockAdapter(api);
  mock.onGet('/me/venues').reply(200, { data: [VENUE] });
  // Creating for the one venue the operator runs — the list screen pre-selects
  // it, so the form never has to ask.
  mockRouter.params = { placeId: '10' };
  mockRouter.back.mockClear();
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

describe('unit conversion', () => {
  it('stores a fixed amount in MINOR units', () => {
    expect(toDiscountValue('fixed_amount', '3.50')).toBe(350);
    expect(toDiscountValue('fixed_amount', '10')).toBe(1000);
    // A comma decimal separator is what a Spanish-locale keyboard produces.
    expect(toDiscountValue('fixed_amount', '3,50')).toBe(350);
  });

  it('rounds a fractional cent rather than dropping it', () => {
    expect(toDiscountValue('fixed_amount', '3.999')).toBe(400);
  });

  it('keeps a percentage and an item count as whole numbers', () => {
    expect(toDiscountValue('percent', '20')).toBe(20);
    // A fractional percentage is not a thing the API stores.
    expect(toDiscountValue('percent', '20.7')).toBe(20);
    expect(toDiscountValue('free_item', '2')).toBe(2);
  });

  it('reads empty, zero, and garbage as no value at all', () => {
    expect(toDiscountValue('percent', '')).toBe(0);
    expect(toDiscountValue('percent', '0')).toBe(0);
    expect(toDiscountValue('percent', '-5')).toBe(0);
    expect(toDiscountValue('fixed_amount', 'abc')).toBe(0);
  });

  it('reads an empty quota field as "no cap", not as zero', () => {
    // Zero would mean "created already exhausted" — the API rejects it, and it
    // is never what an operator who left the field blank meant.
    expect(toNullableInt('')).toBeNull();
    expect(toNullableInt('0')).toBeNull();
    expect(toNullableInt('100')).toBe(100);
  });
});

describe('the form', () => {
  it('sends a percentage offer with a bounded window', async () => {
    mock.onPost('/offers').reply(201, { data: {} });

    render(<OfferFormScreen />, { wrapper });

    fireEvent.changeText(await screen.findByTestId('offer-value'), '25');
    fireEvent.changeText(screen.getByTestId('offer-title'), 'Quarter off lunch');
    // Not the preselected default — pressing it has to actually move the window.
    fireEvent.press(screen.getByText('60 days'));
    fireEvent.press(screen.getByTestId('offer-submit'));

    await waitFor(() => expect(mock.history.post).toHaveLength(1));

    const body = JSON.parse(mock.history.post[0].data);
    expect(body).toMatchObject({
      place_id: '10',
      title: 'Quarter off lunch',
      discount_type: 'percent',
      discount_value: 25,
      status: 'active',
    });

    const days = (new Date(body.ends_at).getTime() - new Date(body.starts_at).getTime()) / 86_400_000;
    expect(Math.round(days)).toBe(60);
  });

  /*
   * A create always carries a bounded window: leaving `ends_at` off would hand
   * the API an open-ended offer, which is the one shape 06 §2.2's 90-day cap
   * exists to prevent.
   */
  /*
   * A deep link (a push, a restored route) can land here without `placeId`. An
   * operator with one venue gets no picker, so the form would be permanently
   * unsubmittable and silent about why.
   */
  it('falls back to the operator\'s sole venue when the route omits placeId', async () => {
    mockRouter.params = {};
    mock.onPost('/offers').reply(201, { data: {} });

    render(<OfferFormScreen />, { wrapper });

    fireEvent.changeText(await screen.findByTestId('offer-value'), '10');
    fireEvent.changeText(screen.getByTestId('offer-title'), 'No placeId in the link');
    fireEvent.press(screen.getByTestId('offer-submit'));

    await waitFor(() => expect(mock.history.post).toHaveLength(1));
    expect(JSON.parse(mock.history.post[0].data).place_id).toBe('10');
  });

  it('sends a bounded window even when no run length is touched', async () => {
    mock.onPost('/offers').reply(201, { data: {} });

    render(<OfferFormScreen />, { wrapper });

    fireEvent.changeText(await screen.findByTestId('offer-value'), '10');
    fireEvent.changeText(screen.getByTestId('offer-title'), 'Untouched window');
    fireEvent.press(screen.getByTestId('offer-submit'));

    await waitFor(() => expect(mock.history.post).toHaveLength(1));

    const body = JSON.parse(mock.history.post[0].data);
    expect(body.ends_at).toBeTruthy();
    const days = (new Date(body.ends_at).getTime() - new Date(body.starts_at).getTime()) / 86_400_000;
    expect(Math.round(days)).toBe(30);
  });

  /*
   * An untouched form is not a 0% offer. "0%" in the preview is a promise the
   * operator never made, and it is the first thing on the screen.
   */
  it('shows a placeholder preview instead of promising 0% before anything is typed', async () => {
    render(<OfferFormScreen />, { wrapper });
    await screen.findByTestId('offer-value');

    expect(screen.queryByText('0%')).toBeNull();
    expect(screen.getByText('—')).toBeTruthy();
    // ...and never "no end date", which would contradict the 90-day cap the
    // form enforces. Matched on the end-date phrase ALONE — pinning it to a
    // rendered date would make the assertion pass for the wrong reason on every
    // day but the one it was written on.
    expect(screen.queryByText(/no end date/)).toBeNull();
  });

  it('converts a fixed amount to minor units on the wire', async () => {
    mock.onPost('/offers').reply(201, { data: {} });

    render(<OfferFormScreen />, { wrapper });

    fireEvent.press(await screen.findByText('Amount off'));
    fireEvent.changeText(screen.getByTestId('offer-value'), '3.50');
    fireEvent.changeText(screen.getByTestId('offer-title'), 'Three fifty off');
    fireEvent.press(screen.getByText('14 days'));
    fireEvent.press(screen.getByTestId('offer-submit'));

    await waitFor(() => expect(mock.history.post).toHaveLength(1));
    expect(JSON.parse(mock.history.post[0].data)).toMatchObject({
      discount_type: 'fixed_amount',
      discount_value: 350,
    });
  });

  it('clears the value when the discount unit changes', async () => {
    render(<OfferFormScreen />, { wrapper });

    // "20" means 20% here and €0.20 after the switch — carrying it over would
    // silently turn a fifth off the bill into twenty cents.
    fireEvent.changeText(await screen.findByTestId('offer-value'), '20');
    fireEvent.press(screen.getByText('Amount off'));

    expect(screen.getByTestId('offer-value').props.value).toBe('');
  });

  it('previews the offer card the diner will see as the operator types', async () => {
    render(<OfferFormScreen />, { wrapper });

    fireEvent.changeText(await screen.findByTestId('offer-value'), '15');
    fireEvent.changeText(screen.getByTestId('offer-title'), 'Fifteen off');

    // The preview goes through the real card component, so this is the same
    // rendering the list produces — a preview built separately could lie.
    expect(screen.getByText('15%')).toBeTruthy();
    expect(screen.getByText('Fifteen off')).toBeTruthy();
  });

  it('refuses to submit without a title or a value', async () => {
    render(<OfferFormScreen />, { wrapper });

    const submit = await screen.findByTestId('offer-submit');
    expect(submit.props.accessibilityState.disabled).toBe(true);

    fireEvent.changeText(screen.getByTestId('offer-value'), '20');
    expect(screen.getByTestId('offer-submit').props.accessibilityState.disabled).toBe(true);

    fireEvent.changeText(screen.getByTestId('offer-title'), 'Now valid');
    expect(screen.getByTestId('offer-submit').props.accessibilityState.disabled).toBe(false);
  });

  it('saves a draft without publishing it', async () => {
    mock.onPost('/offers').reply(201, { data: {} });

    render(<OfferFormScreen />, { wrapper });

    fireEvent.changeText(await screen.findByTestId('offer-value'), '20');
    fireEvent.changeText(screen.getByTestId('offer-title'), 'Later');
    fireEvent.press(screen.getByText('Save as draft'));

    await waitFor(() => expect(mock.history.post).toHaveLength(1));
    expect(JSON.parse(mock.history.post[0].data)).toMatchObject({ status: 'draft' });
  });

  it('surfaces the API field error against the field that caused it', async () => {
    mock.onPost('/offers').reply(422, {
      error: { code: 'validation_failed', details: { discount_value: ['A percentage discount must be between 5% and 50%.'] } },
    });

    render(<OfferFormScreen />, { wrapper });

    fireEvent.changeText(await screen.findByTestId('offer-value'), '90');
    fireEvent.changeText(screen.getByTestId('offer-title'), 'Too generous');
    fireEvent.press(screen.getByTestId('offer-submit'));

    expect(await screen.findByText('A percentage discount must be between 5% and 50%.')).toBeTruthy();
  });
});

describe('editing an existing offer', () => {
  const STORED = {
    id: '7',
    place_id: '10',
    title: 'Half-price Tuesdays',
    description: null,
    discount_type: 'fixed_amount' as const,
    discount_value: 350,
    terms: 'One per table.',
    starts_at: '2026-07-01T00:00:00Z',
    ends_at: '2026-07-31T00:00:00Z',
    quota_total: 100,
    quota_per_user: 1,
    quota_per_day: 10,
    redemptions_count: 12,
    remaining_quota: 88,
    status: 'active' as const,
    is_redeemable: true,
    created_at: null,
    updated_at: null,
  };

  beforeEach(() => {
    mockRouter.params = { id: '7' };
    mock.onGet('/offers/7').reply(200, { data: STORED });
    mock.onPatch('/offers/7').reply(200, { data: STORED });
  });

  it('seeds the fields from the stored offer, converting minor units back', async () => {
    render(<OfferFormScreen />, { wrapper });

    // 350 minor units must come back as "3.50", not as "350".
    expect((await screen.findByTestId('offer-value')).props.value).toBe('3.50');
    expect(screen.getByTestId('offer-title').props.value).toBe('Half-price Tuesdays');
    expect(screen.getByTestId('offer-quota-total').props.value).toBe('100');
    expect(screen.getByTestId('offer-quota-per-day').props.value).toBe('10');
    expect(screen.getByTestId('offer-terms').props.value).toBe('One per table.');
  });

  /*
   * The window is the trap: a PATCH that always sent dates would re-base a
   * running promotion's end date from today every time someone fixed a typo in
   * its name.
   */
  it('leaves the window alone when only the name was edited', async () => {
    render(<OfferFormScreen />, { wrapper });

    fireEvent.changeText(await screen.findByTestId('offer-title'), 'Renamed');
    fireEvent.press(screen.getByTestId('offer-submit'));

    await waitFor(() => expect(mock.history.patch).toHaveLength(1));

    const body = JSON.parse(mock.history.patch[0].data);
    expect(body.title).toBe('Renamed');
    expect(body).not.toHaveProperty('starts_at');
    expect(body).not.toHaveProperty('ends_at');
  });

  it('re-bases the window from the stored start when a new run length is picked', async () => {
    render(<OfferFormScreen />, { wrapper });

    fireEvent.press(await screen.findByText('60 days'));
    fireEvent.press(screen.getByTestId('offer-submit'));

    await waitFor(() => expect(mock.history.patch).toHaveLength(1));

    const body = JSON.parse(mock.history.patch[0].data);
    // Measured from the offer's own start, never from today — otherwise the cap
    // would reset every time an operator extended a promotion.
    expect(body.starts_at).toBe(STORED.starts_at);
    expect(new Date(body.ends_at).toISOString()).toBe('2026-08-30T00:00:00.000Z');
  });

  it('previews against redemptions already taken, not against zero', async () => {
    render(<OfferFormScreen />, { wrapper });

    expect(await screen.findByText('12 of 100 redeemed')).toBeTruthy();
  });

  /*
   * The defect this pins: `submit()` used to always send a status, and the
   * primary button passes 'active'. An operator who paused an offer, fixed a
   * typo in its terms and pressed Save had silently put it back in front of
   * diners. Lifecycle belongs to the list screen's explicit one-tap actions.
   */
  it('never changes the offer status on a save', async () => {
    mock.onGet('/offers/7').reply(200, { data: { ...STORED, status: 'paused', is_redeemable: false } });

    render(<OfferFormScreen />, { wrapper });

    fireEvent.changeText(await screen.findByTestId('offer-title'), 'Fixed a typo');
    fireEvent.press(screen.getByTestId('offer-submit'));

    await waitFor(() => expect(mock.history.patch).toHaveLength(1));
    expect(JSON.parse(mock.history.patch[0].data)).not.toHaveProperty('status');
  });

  it('offers a retry instead of an empty form when the offer cannot be fetched', async () => {
    mock.onGet('/offers/7').reply(500);

    render(<OfferFormScreen />, { wrapper });

    expect(await screen.findByText('Try again')).toBeTruthy();
    // The empty create-style form must NOT be what an operator sees here.
    expect(screen.queryByTestId('offer-submit')).toBeNull();
  });

  it('previews a paused offer as paused, not as live', async () => {
    mock.onGet('/offers/7').reply(200, { data: { ...STORED, status: 'paused', is_redeemable: false } });

    render(<OfferFormScreen />, { wrapper });

    expect(await screen.findByText('Paused')).toBeTruthy();
    expect(screen.queryByText('Live')).toBeNull();
  });

  it('does not offer "save as draft" on an offer that is already published', async () => {
    render(<OfferFormScreen />, { wrapper });
    await screen.findByTestId('offer-submit');

    expect(screen.queryByText('Save as draft')).toBeNull();
  });
});
