import { isPausable, type Offer, offerState } from '../offers';

/**
 * `offerState()` (T-042) — the derivation the whole restaurant surface hangs on.
 *
 * The property under test: **`status` alone can never answer "is this running".**
 * It reads `active` from the moment an offer is published and nothing rewrites
 * it when the window opens or closes, so a badge built on the column alone would
 * tell an operator that last month's promotion is still live. Every case below
 * is one where the column and the truth disagree.
 */
const NOW = new Date('2026-08-03T12:00:00Z');

function offer(overrides: Partial<Offer> = {}): Offer {
  return {
    id: '1',
    place_id: '10',
    title: 'Two-for-one pastéis',
    description: null,
    discount_type: 'percent',
    discount_value: 20,
    terms: null,
    starts_at: '2026-08-01T00:00:00Z',
    ends_at: '2026-09-01T00:00:00Z',
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

describe('offerState', () => {
  it('reads an in-window active offer as live', () => {
    expect(offerState(offer(), NOW)).toBe('live');
  });

  it('reads a lapsed window as ended even though status still says active', () => {
    const lapsed = offer({ ends_at: '2026-08-02T00:00:00Z' });

    expect(lapsed.status).toBe('active');
    expect(offerState(lapsed, NOW)).toBe('ended');
  });

  it('reads a future window as scheduled, not live', () => {
    expect(offerState(offer({ starts_at: '2026-08-10T00:00:00Z' }), NOW)).toBe('scheduled');
  });

  it('treats a null end date as open-ended rather than as already ended', () => {
    expect(offerState(offer({ ends_at: null }), NOW)).toBe('live');
  });

  it('distinguishes a sold-out offer from a live one', () => {
    // The fix for this state is raising the quota, not extending the dates —
    // which is exactly why it is not folded into `ended`.
    expect(offerState(offer({ quota_total: 10, redemptions_count: 10, remaining_quota: 0 }), NOW)).toBe('soldOut');
  });

  it('still reads a sold-out offer as sold out, not live, on its last day', () => {
    const soldOut = offer({
      ends_at: '2026-08-04T00:00:00Z',
      quota_total: 5,
      redemptions_count: 5,
      remaining_quota: 0,
    });

    expect(offerState(soldOut, NOW)).toBe('soldOut');
  });

  it.each(['draft', 'paused', 'archived'] as const)('passes %s through from the status column', (status) => {
    expect(offerState(offer({ status }), NOW)).toBe(status);
  });

  /*
   * A paused offer inside its window must NOT read as live: pausing is how an
   * operator (or an admin, post-hoc) stops a promotion they can't honour today.
   */
  it('never reads a paused in-window offer as live', () => {
    expect(offerState(offer({ status: 'paused' }), NOW)).toBe('paused');
  });
});

describe('isPausable', () => {
  it('offers a pause for every state a diner can still reach', () => {
    expect(isPausable('live')).toBe(true);
    expect(isPausable('scheduled')).toBe(true);
    // Sold out today, but the quota could be raised — it is still published.
    expect(isPausable('soldOut')).toBe(true);
  });

  it('does not offer a pause for what is already stopped', () => {
    expect(isPausable('draft')).toBe(false);
    expect(isPausable('paused')).toBe(false);
    expect(isPausable('ended')).toBe(false);
    expect(isPausable('archived')).toBe(false);
  });
});
