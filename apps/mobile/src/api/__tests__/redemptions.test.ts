import { codeState, formatRemaining, secondsRemaining, type Redemption } from '../redemptions';

/**
 * The pure code-state logic (T-047).
 *
 * Everything here exists because `status` alone cannot answer "can this be
 * presented at a till right now". The expiry sweep is a scheduled job, so a
 * lapsed code keeps reading `issued` until it catches up — and a screen that
 * trusts the column offers a dead code to staff, which is a customer being
 * refused at a counter.
 */
const NOW = new Date('2026-08-03T12:00:00Z');

function redemption(overrides: Partial<Redemption> = {}): Redemption {
  return {
    id: '1',
    offer_id: '7',
    status: 'issued',
    is_live: true,
    issued_at: '2026-08-03T11:45:00Z',
    expires_at: '2026-08-03T12:15:00Z',
    redeemed_at: null,
    attribution: { influencer_id: null, share_id: null },
    ...overrides,
  };
}

describe('codeState', () => {
  it('is active while issued and inside the window', () => {
    expect(codeState(redemption(), NOW)).toBe('active');
  });

  it('is expired for a code the sweep has not caught up with yet', () => {
    // Still `issued` server-side, but the clock has passed — the case the
    // whole function exists for.
    const stale = redemption({ expires_at: '2026-08-03T11:59:59Z' });

    expect(stale.status).toBe('issued');
    expect(codeState(stale, NOW)).toBe('expired');
  });

  it('treats the exact expiry instant as expired, not as one last second', () => {
    expect(codeState(redemption({ expires_at: NOW.toISOString() }), NOW)).toBe('expired');
  });

  it('reads verified from the status even when the window has since lapsed', () => {
    // A code redeemed at 11:50 whose window closed at 11:59 is DONE, not
    // expired — showing "expired" to the diner who just paid is the wrong story.
    const used = redemption({
      status: 'redeemed',
      redeemed_at: '2026-08-03T11:50:00Z',
      expires_at: '2026-08-03T11:59:00Z',
    });

    expect(codeState(used, NOW)).toBe('verified');
  });

  it('distinguishes a voided code from an expired one', () => {
    expect(codeState(redemption({ status: 'void' }), NOW)).toBe('void');
  });
});

describe('secondsRemaining', () => {
  it('counts down from the server expiry, not from a local timer', () => {
    expect(secondsRemaining(redemption({ expires_at: '2026-08-03T12:02:30Z' }), NOW)).toBe(150);
  });

  it('floors at zero rather than going negative', () => {
    expect(secondsRemaining(redemption({ expires_at: '2026-08-03T11:00:00Z' }), NOW)).toBe(0);
  });

  it('is zero when the API sent no expiry at all', () => {
    expect(secondsRemaining(redemption({ expires_at: null }), NOW)).toBe(0);
  });
});

describe('formatRemaining', () => {
  it('drops to seconds only in the last minute', () => {
    expect(formatRemaining(45)).toBe('45s');
  });

  it('shows minutes AND seconds while the wait is still tense', () => {
    expect(formatRemaining(150)).toBe('2m 30s');
  });

  it('coarsens to hours and minutes when precision would be noise', () => {
    expect(formatRemaining(3600 + 41 * 60)).toBe('1h 41m');
  });

  it('renders a lapsed code as 0s rather than an empty string', () => {
    expect(formatRemaining(0)).toBe('0s');
  });
});
