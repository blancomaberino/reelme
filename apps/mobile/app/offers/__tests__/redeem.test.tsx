import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import RedeemScreen from '../[id]/redeem';
import { api } from '@/api/client';
import type { Redemption } from '@/api/redemptions';
import { mockRouter } from '@/../jest.setup';

/**
 * The diner's code screen (T-047).
 *
 * The three states are the product: a live code, a verified one, and a lapsed
 * one. Each pins a way the screen could plausibly mislead someone standing at a
 * counter — showing a dead code as live, showing a paid-for code as expired, or
 * answering every refusal with the same shrug.
 */
let mock: AxiosMockAdapter;
let qc: QueryClient;

function redemption(overrides: Partial<Redemption> = {}): Redemption {
  return {
    id: '55',
    offer_id: '7',
    status: 'issued',
    is_live: true,
    issued_at: new Date(Date.now() - 60_000).toISOString(),
    expires_at: new Date(Date.now() + 15 * 60_000).toISOString(),
    redeemed_at: null,
    code: '7F3K92QXAB',
    code_display: '7F3K-92QX-AB',
    qr_payload: 'v1.7F3K92QXAB.sig',
    attribution: { influencer_id: '3', share_id: '9' },
    ...overrides,
  };
}

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  mock = new AxiosMockAdapter(api);
  qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  mockRouter.params = { id: '7' };
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

describe('claiming a code', () => {
  it('issues on tap and shows the live code with a countdown', async () => {
    const row = redemption();
    mock.onPost('/redemptions').reply(201, { data: row });
    mock.onGet('/redemptions/55').reply(200, { data: row });

    render(<RedeemScreen />, { wrapper });
    fireEvent.press(screen.getByTestId('redeem-cta'));

    await waitFor(() => expect(screen.getByTestId('redeem-active')).toBeTruthy());

    // The grouped form the API sent — never re-derived on the device, or the
    // code a diner reads aloud stops matching the one staff type in.
    expect(screen.getByText('7F3K-92QX-AB')).toBeTruthy();
    expect(screen.getByTestId('redeem-countdown')).toBeTruthy();
  });

  /*
   * The referral context is what T-043 freezes as attribution. Losing it here
   * means the influencer who actually sent this diner is not the one paid, and
   * nothing downstream can reconstruct it.
   */
  it('threads the share it was opened from into the issue request', async () => {
    mockRouter.params = { id: '7', shareId: '9' };
    const row = redemption();
    mock.onPost('/redemptions').reply(201, { data: row });
    mock.onGet('/redemptions/55').reply(200, { data: row });

    render(<RedeemScreen />, { wrapper });
    fireEvent.press(screen.getByTestId('redeem-cta'));

    await waitFor(() => expect(mock.history.post).toHaveLength(1));
    expect(JSON.parse(mock.history.post[0].data)).toMatchObject({ offer_id: 7, share_id: 9 });
    // A retry on flaky restaurant wifi must not mint a second code.
    expect(mock.history.post[0].headers?.['Idempotency-Key']).toBeTruthy();
  });

  /*
   * 06 §3 spreads the refusals across four HTTP statuses, and the client's
   * response interceptor rewrites ONLY the 422s into a `ValidationError` — the
   * reason then rides in `fields`, not in the raw envelope. A reader that
   * handled one shape answered half of these with the generic shrug, which is
   * what teaches a diner to keep tapping the button.
   */
  it.each([
    [422, 'user_quota_reached', 'You have already used this offer.'],
    [429, 'velocity_exceeded', 'You have claimed a lot of offers today. Try again tomorrow.'],
    [422, 'cooldown', 'You redeemed here recently. Come back in a few days.'],
    [403, 'self_dealing', 'You cannot redeem offers at a restaurant you run.'],
  ])('explains a %i %s specifically rather than failing generically', async (status, reason, copy) => {
    mock.onPost('/redemptions').reply(status, { error: { details: { reason } } });

    render(<RedeemScreen />, { wrapper });
    fireEvent.press(screen.getByTestId('redeem-cta'));

    await waitFor(() => expect(screen.getByTestId('redeem-error')).toHaveTextContent(copy));
  });

  /*
   * The server refuses a duplicate without naming the code it already issued.
   * Telling the diner "you already have one" and stopping there is a dead end
   * at the exact moment they are standing at the counter — so we find it and
   * show it.
   */
  it('shows the code the diner already holds instead of dead-ending on already_issued', async () => {
    mock.onPost('/redemptions').reply(409, { error: { details: { reason: 'already_issued' } } });
    mock.onGet('/me/redemptions').reply(200, {
      data: [
        // A different offer's code, and a spent one for THIS offer — neither is
        // the live code we are looking for.
        redemption({ id: '90', offer_id: '99' }),
        redemption({ id: '91', status: 'redeemed', redeemed_at: new Date().toISOString() }),
        redemption({ id: '55' }),
      ],
    });
    mock.onGet('/redemptions/55').reply(200, { data: redemption() });

    render(<RedeemScreen />, { wrapper });
    fireEvent.press(screen.getByTestId('redeem-cta'));

    await waitFor(() => expect(screen.getByTestId('redeem-active')).toBeTruthy());
    expect(screen.getByText('7F3K-92QX-AB')).toBeTruthy();
    // And the refusal copy is gone — it would contradict the code on screen.
    expect(screen.queryByTestId('redeem-error')).toBeNull();
  });

  it('still explains the refusal when no live code can be found', async () => {
    mock.onPost('/redemptions').reply(409, { error: { details: { reason: 'already_issued' } } });
    mock.onGet('/me/redemptions').reply(200, { data: [] });

    render(<RedeemScreen />, { wrapper });
    fireEvent.press(screen.getByTestId('redeem-cta'));

    await waitFor(() =>
      expect(screen.getByTestId('redeem-error')).toHaveTextContent(
        'You already have a code for this offer — check your codes.',
      ),
    );
  });

  it('falls back to the generic message for a reason it has no copy for', async () => {
    mock.onPost('/redemptions').reply(422, { error: { details: { reason: 'something_new' } } });

    render(<RedeemScreen />, { wrapper });
    fireEvent.press(screen.getByTestId('redeem-cta'));

    await waitFor(() => expect(screen.getByTestId('redeem-error')).toBeTruthy());
    expect(screen.queryByText(/already have a live code/)).toBeNull();
  });
});

describe('the state machine', () => {
  it('shows verified once the till has scanned it', async () => {
    mockRouter.params = { id: '7', redemptionId: '55' };
    mock.onGet('/redemptions/55').reply(200, {
      data: redemption({ status: 'redeemed', redeemed_at: new Date().toISOString() }),
    });

    render(<RedeemScreen />, { wrapper });

    await waitFor(() => expect(screen.getByTestId('redeem-verified')).toBeTruthy());
    // The code is gone the moment it is spent — leaving it on screen invites a
    // second presentation that can only be refused.
    expect(screen.queryByText('7F3K-92QX-AB')).toBeNull();
  });

  /*
   * The row still reads `issued` — the sweep has not run — but the window shut.
   * Presenting it would send the diner to a counter for a refusal.
   */
  it('shows expired for a lapsed code the server still calls issued', async () => {
    mockRouter.params = { id: '7', redemptionId: '55' };
    mock.onGet('/redemptions/55').reply(200, {
      data: redemption({ expires_at: new Date(Date.now() - 1000).toISOString() }),
    });

    render(<RedeemScreen />, { wrapper });

    await waitFor(() => expect(screen.getByTestId('redeem-expired')).toBeTruthy());
    expect(screen.queryByTestId('redeem-active')).toBeNull();
  });

  it('offers a fresh code after one lapses instead of dead-ending', async () => {
    mockRouter.params = { id: '7', redemptionId: '55' };
    mock.onGet('/redemptions/55').reply(200, {
      data: redemption({ expires_at: new Date(Date.now() - 1000).toISOString() }),
    });

    render(<RedeemScreen />, { wrapper });
    await waitFor(() => expect(screen.getByTestId('redeem-again')).toBeTruthy());

    fireEvent.press(screen.getByTestId('redeem-again'));

    // Back to the claim step — the offer may well still be running.
    await waitFor(() => expect(screen.getByTestId('redeem-cta')).toBeTruthy());
  });
});
