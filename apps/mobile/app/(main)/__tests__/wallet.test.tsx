import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';
import { Alert } from 'react-native';

import WalletScreen from '../wallet';
import { api } from '@/api/client';
import type { Wallet } from '@/api/wallet';

/**
 * The wallet screen (T-046, 05 screen #21).
 *
 * The organising property: **the screen never decides anything about money it
 * could get wrong.** Whether a payout is possible is computed server-side and
 * obeyed here; the two balances are shown separately so a requested payout does
 * not look like money that vanished; and the Connect banner surfaces Stripe's
 * requirements rather than letting a cash-out fail to reveal them.
 */
let qc: QueryClient;
let mock: AxiosMockAdapter;

function wallet(overrides: Partial<Wallet> = {}): Wallet {
  return {
    balance: {
      available: { amount: 4230, currency: 'EUR' },
      pending: { amount: 0, currency: 'EUR' },
    },
    lifetime_earnings: { amount: 12800, currency: 'EUR' },
    connect: { onboarded: true, payouts_enabled: true, requirements_due: [] },
    minimum_payout: { amount: 2500, currency: 'EUR' },
    recent_entries: [],
    can_request_payout: true,
    ...overrides,
  };
}

function serve(data: Wallet, entries: unknown[] = []) {
  mock.onGet('/wallet').reply(200, { data });
  mock.onGet('/wallet/ledger').reply(200, { data: entries, meta: { pagination: { next_cursor: null } } });
}

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: 0 } } });
  mock = new AxiosMockAdapter(api);
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

describe('balances', () => {
  it('renders minor units as money, never as a bare number', async () => {
    serve(wallet());

    render(<WalletScreen />, { wrapper });

    // 4230 minor units is €42.30 — shown raw it reads as forty-two hundred.
    expect(await screen.findByTestId('wallet-available')).toHaveTextContent('€42.30');
  });

  /*
   * Without a separate pending line, requesting a payout looks like money that
   * disappeared between two visits to the screen.
   */
  it('shows money in flight separately from money available', async () => {
    serve(wallet({ balance: { available: { amount: 0, currency: 'EUR' }, pending: { amount: 5000, currency: 'EUR' } } }));

    render(<WalletScreen />, { wrapper });

    expect(await screen.findByTestId('wallet-available')).toHaveTextContent('€0.00');
    expect(screen.getByTestId('wallet-pending')).toHaveTextContent(/€50\.00/);
  });

  it('hides the pending line when nothing is in flight', async () => {
    serve(wallet());

    render(<WalletScreen />, { wrapper });
    await screen.findByTestId('wallet-available');

    expect(screen.queryByTestId('wallet-pending')).toBeNull();
  });

  it('shows fees owed only for a restaurant operator', async () => {
    serve(wallet({ fees_owed: { amount: 900, currency: 'EUR' } }));

    render(<WalletScreen />, { wrapper });

    expect(await screen.findByText('€9.00')).toBeTruthy();
  });
});

describe('cashing out', () => {
  /*
   * The rule is "enough money AND Stripe will accept it", and it is computed
   * server-side. A client deriving it from the two fields would drift the day
   * either rule changes — so the screen obeys the flag rather than re-deciding.
   */
  it('disables the button when the server says it cannot be done', async () => {
    serve(wallet({ can_request_payout: false, balance: { available: { amount: 500, currency: 'EUR' }, pending: { amount: 0, currency: 'EUR' } } }));

    render(<WalletScreen />, { wrapper });

    const cta = await screen.findByTestId('wallet-payout-cta');
    expect(cta.props.accessibilityState.disabled).toBe(true);
    // ...and says why, rather than leaving a dead button.
    expect(screen.getByText(/€25\.00/)).toBeTruthy();
  });

  it('confirms before moving money', async () => {
    serve(wallet());
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);

    render(<WalletScreen />, { wrapper });
    fireEvent.press(await screen.findByTestId('wallet-payout-cta'));

    expect(alert).toHaveBeenCalled();
    expect(mock.history.post).toHaveLength(0);
    alert.mockRestore();
  });

  it('sends an Idempotency-Key so a retry cannot pay twice', async () => {
    serve(wallet());
    mock.onPost('/wallet/payouts').reply(201, { data: { id: '1', amount: 4230, currency: 'EUR', status: 'processing', period_start: '2026-08-01', period_end: '2026-08-31', failure_reason: null, paid_at: null } });
    const alert = jest.spyOn(Alert, 'alert').mockImplementation((_t, _m, buttons) => {
      (buttons as { text: string; onPress?: () => void }[])[1].onPress?.();
    });

    render(<WalletScreen />, { wrapper });
    fireEvent.press(await screen.findByTestId('wallet-payout-cta'));

    await waitFor(() => expect(mock.history.post).toHaveLength(1));
    expect(mock.history.post[0].headers?.['Idempotency-Key']).toEqual(expect.stringMatching(/^payout-/));
    alert.mockRestore();
  });
});

describe('the Connect banner', () => {
  it('prompts setup when payouts were never enabled', async () => {
    serve(wallet({ connect: { onboarded: false, payouts_enabled: false, requirements_due: ['individual.id_number'] }, can_request_payout: false }));

    render(<WalletScreen />, { wrapper });

    expect(await screen.findByTestId('wallet-connect-banner')).toBeTruthy();
    expect(screen.getByText('Set up payouts')).toBeTruthy();
  });

  /*
   * Stripe re-verifies: an account that onboarded months ago can stop being
   * payable, and without this the first sign would be a failed cash-out.
   */
  it('warns when Stripe wants something from an already-onboarded account', async () => {
    serve(wallet({ connect: { onboarded: true, payouts_enabled: true, requirements_due: ['individual.verification.document'] } }));

    render(<WalletScreen />, { wrapper });

    expect(await screen.findByText('Stripe needs something')).toBeTruthy();
  });

  it('stays out of the way when nothing is required', async () => {
    serve(wallet());

    render(<WalletScreen />, { wrapper });
    await screen.findByTestId('wallet-available');

    expect(screen.queryByTestId('wallet-connect-banner')).toBeNull();
  });

  it('asks the API for a fresh onboarding link', async () => {
    serve(wallet({ connect: { onboarded: false, payouts_enabled: false, requirements_due: [] }, can_request_payout: false }));
    mock.onPost('/wallet/connect/onboarding-link').reply(200, { data: { url: 'https://connect.stripe.test/setup/acct_1' } });

    render(<WalletScreen />, { wrapper });
    fireEvent.press(await screen.findByTestId('wallet-connect-cta'));

    // Minted per press — links expire in minutes and are single-use.
    await waitFor(() => expect(mock.history.post.filter((r) => r.url === '/wallet/connect/onboarding-link')).toHaveLength(1));
  });
});

describe('the statement', () => {
  it('signs each entry for a reader', async () => {
    serve(wallet(), [
      { id: '1', type: 'revenue_share', direction: 'credit', amount: 150, currency: 'EUR', memo: 'Attributed redemption', created_at: '2026-08-01T10:00:00Z' },
      { id: '2', type: 'payout', direction: 'debit', amount: 4230, currency: 'EUR', memo: null, created_at: '2026-08-02T10:00:00Z' },
    ]);

    render(<WalletScreen />, { wrapper });

    // The ledger stores a positive amount and a direction; a statement means
    // "+€1.50" and "−€42.30".
    expect(await screen.findByText('+€1.50')).toBeTruthy();
    expect(screen.getByText('−€42.30')).toBeTruthy();
    expect(screen.getByText('Revenue share')).toBeTruthy();
    expect(screen.getByText('Paid out')).toBeTruthy();
  });

  it('explains an empty statement rather than showing a blank list', async () => {
    serve(wallet());

    render(<WalletScreen />, { wrapper });

    expect(await screen.findByText(/Nothing here yet/)).toBeTruthy();
  });
});
