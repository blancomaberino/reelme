// Wallet + payout types (T-046) — GET /wallet, /wallet/ledger, /wallet/payouts.
//
// Money is ALWAYS `{amount, currency}` with `amount` in MINOR units (03 §3.5).
// Never a bare number and never a float: a bare number is the shape that gets
// read as euros somewhere, and a float is a rounding error waiting for a ledger
// to find it.

/** An amount and the unit it is in. */
export type Money = {
  /** Minor units — 4230 is €42.30. */
  amount: number;
  currency: string;
};

/** What a wallet entry MEANS to a person, not which ledger account it hit. */
export type WalletEntryType = 'revenue_share' | 'escrow_release' | 'payout' | 'adjustment';

export type WalletEntry = {
  id: string;
  type: WalletEntryType;
  direction: 'debit' | 'credit';
  /** Minor units. Always positive — the sign lives in `direction`. */
  amount: number;
  currency: string;
  memo: string | null;
  created_at: string;
};

/**
 * Stripe Connect state.
 *
 * `onboarded` and `payouts_enabled` are the same fact today, and both are read
 * LIVE — Stripe re-verifies, so `requirements_due` can refill months after
 * onboarding finished. A screen that trusted a cached flag would offer a button
 * that fails.
 */
export type ConnectState = {
  onboarded: boolean;
  payouts_enabled: boolean;
  requirements_due: string[];
};

export type Wallet = {
  balance: {
    /** Cashable now. Already excludes anything an in-flight payout is holding. */
    available: Money;
    /** Committed to a payout Stripe has not settled. */
    pending: Money;
  };
  /** Everything ever earned — credits only, so a payout does not reduce it. */
  lifetime_earnings: Money;
  connect: ConnectState;
  minimum_payout: Money;
  recent_entries: WalletEntry[];
  /** Computed server-side: enough money AND Stripe will accept it. */
  can_request_payout: boolean;
  /** Restaurant operators only — what their venues owe (06 §2.3). */
  fees_owed?: Money;
};

export type PayoutStatus = 'pending' | 'processing' | 'paid' | 'failed' | 'reversed';

export type Payout = {
  id: string;
  amount: number;
  currency: string;
  status: PayoutStatus;
  period_start: string;
  period_end: string;
  failure_reason: string | null;
  paid_at: string | null;
};

/**
 * Minor units → a display string.
 *
 * Divides by 100 for DISPLAY only; nothing rounds on the way back, because
 * nothing goes back — the client never sends an amount. The payout is always
 * "all of it", so there is no field to mis-parse.
 */
export function formatMoney(money: Money): string {
  const symbol = money.currency === 'EUR' ? '€' : money.currency === 'GBP' ? '£' : '$';

  return `${symbol}${(money.amount / 100).toFixed(2)}`;
}

/** Has Stripe asked for something before this account can be paid? */
export function needsAttention(connect: ConnectState): boolean {
  return !connect.payouts_enabled || connect.requirements_due.length > 0;
}
