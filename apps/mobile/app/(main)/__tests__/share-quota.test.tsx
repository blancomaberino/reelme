import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import ShareScreen from '../share';
import { api } from '@/api/client';
import { useSessionStore } from '@/stores/session';
import { useUiStore } from '@/stores/ui';

/**
 * The daily share allowance on the share screen (T-051, NFR-12).
 *
 * The whole point of putting the quota on `GET /me` is that the screen can say
 * "daily limit reached" BEFORE the tap. Discovering a designed limit by being
 * refused reads as a bug — and it spends a request on a rejection.
 */
let qc: QueryClient;
let mock: AxiosMockAdapter;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

function meWithQuota(remaining: number) {
  return {
    data: { user: { id: '2', username: 'user1', name: 'User 1' } },
    meta: {
      quotas: {
        shares: { used: 100 - remaining, limit: 100, remaining },
        ai: { spent_usd: 0, budget_usd: 0.5, remaining_usd: 0.5 },
        resets_at: '2026-08-08T00:00:00+00:00',
      },
    },
  };
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { mutations: { retry: 0 }, queries: { retry: false } } });
  mock = new AxiosMockAdapter(api);
  mock.onGet('/shares').reply(200, { data: [], meta: { pagination: { next_cursor: null } } });
  useSessionStore.setState({ user: null, status: 'authed' });
  useUiStore.setState({ rateLimited: false });
});

afterEach(() => mock.restore());

it('says the limit is reached and refuses to send', async () => {
  mock.onGet('/me').reply(200, meWithQuota(0));
  mock.onPost('/shares').reply(201, { data: {}, meta: {} });

  render(<ShareScreen />, { wrapper: Providers });

  const notice = await screen.findByTestId('share-quota-reached');
  expect(notice).toBeOnTheScreen();
  expect(screen.getByTestId('share-submit').props.accessibilityState.disabled).toBe(true);

  // A URL must actually be entered, or `doSubmit` short-circuits on
  // "nothing to send" and the assertion below proves nothing about the guard —
  // removing `disabled` entirely still left this green.
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://www.instagram.com/reel/ABC/');

  fireEvent.press(screen.getByTestId('share-submit'));
  await waitFor(() => expect(mock.history.post).toHaveLength(0));
});

it('blocks the share-sheet path too, not just the button', async () => {
  mock.onGet('/me').reply(200, meWithQuota(0));
  mock.onPost('/shares').reply(201, { data: {}, meta: {} });
  // Seeded, because the auto-submit fires from the mount effect: with a cold
  // cache the request is already gone before /me answers. The client guard is
  // only ever a courtesy — the server's 429 is the enforcement (below).
  qc.setQueryData(['me', 'quotas'], meWithQuota(0).meta.quotas);
  useUiStore.getState().setPendingShare({ url: 'https://www.instagram.com/reel/ABC/', text: '' });

  render(<ShareScreen />, { wrapper: Providers });

  // The share-sheet path never touches the button — it is the product's
  // PRIMARY entry point, so a guard living only on the button protects the one
  // route nobody uses.
  await screen.findByTestId('share-quota-reached');
  await waitFor(() => expect(mock.history.post).toHaveLength(0));
});

it('explains a daily_quota_exceeded 429 as the daily limit, not as "try again"', async () => {
  mock.onGet('/me').reply(200, meWithQuota(3));
  mock.onPost('/shares').reply(429, {
    error: {
      code: 'daily_quota_exceeded',
      message: 'You have reached your daily share limit.',
      details: { reason: 'daily_shares', limit: 100, resets_at: '2026-08-08T00:00:00+00:00' },
      request_id: 'req_1',
    },
  });

  render(<ShareScreen />, { wrapper: Providers });

  await screen.findByTestId('share-submit');
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://www.instagram.com/reel/ABC/');
  fireEvent.press(screen.getByTestId('share-submit'));

  // The share-sheet auto-submit races /me and can land before the quota is
  // known, so the server's refusal IS the guard on that path. Telling somebody
  // to "try again" invites a retry that cannot work.
  expect(await screen.findByText(/daily limit reached/i)).toBeOnTheScreen();
  expect(screen.queryByText(/please try again/i)).toBeNull();
  // ...and no global "slow down, too many requests" banner contradicting it.
  expect(useUiStore.getState().rateLimited).toBe(false);
});

it('still says "try again" for a BURST 429, which is a different problem', async () => {
  mock.onGet('/me').reply(200, meWithQuota(3));
  // The 10/min share limiter. Same status, opposite advice: waiting a moment
  // fixes this one, and "you are out until midnight" is simply false.
  mock.onPost('/shares').reply(429, {
    error: { code: 'rate_limited', message: 'Too Many Requests', details: {}, request_id: 'req_2' },
  });

  render(<ShareScreen />, { wrapper: Providers });

  await screen.findByTestId('share-submit');
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://www.instagram.com/reel/ABC/');
  fireEvent.press(screen.getByTestId('share-submit'));

  expect(await screen.findByText(/please try again/i)).toBeOnTheScreen();
  expect(screen.queryByText(/daily limit reached/i)).toBeNull();
  expect(useUiStore.getState().rateLimited).toBe(true);
});

it('re-reads the quota after a share, instead of showing the old number', async () => {
  let meCalls = 0;
  mock.onGet('/me').reply(() => {
    meCalls += 1;
    return [200, meWithQuota(meCalls === 1 ? 1 : 0)];
  });
  mock.onPost('/shares').reply(202, { data: { id: '9', status: 'queued' }, meta: {} });

  render(<ShareScreen />, { wrapper: Providers });

  await screen.findByTestId('share-submit');
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://www.instagram.com/reel/ABC/');
  fireEvent.press(screen.getByTestId('share-submit'));

  // The share just sent counts against today's allowance, and the quota has a
  // 30s staleTime — without invalidation the screen keeps the OLD remaining
  // until it happens to expire. On the last share of the day that means the
  // limit notice appears a random half-minute after the action that caused it,
  // which reads as a glitch rather than as a limit.
  await waitFor(() => expect(meCalls).toBeGreaterThan(1));
});

it('stops refusing once the reset time has passed', async () => {
  jest.useFakeTimers();
  try {
    const resetsAt = new Date(Date.now() + 5_000).toISOString();
    let meCalls = 0;
    mock.onGet('/me').reply(() => {
      meCalls += 1;
      // Exhausted before the boundary, replenished after it.
      return [
        200,
        meCalls === 1
          ? { ...meWithQuota(0), meta: { quotas: { ...meWithQuota(0).meta.quotas, resets_at: resetsAt } } }
          : meWithQuota(50),
      ];
    });

    render(<ShareScreen />, { wrapper: Providers });
    await waitFor(() => expect(screen.queryByTestId('share-quota-reached')).not.toBeNull());

    // `staleTime` schedules NOTHING — it only marks data refetchable. A screen
    // left open in the foreground across midnight UTC would hold yesterday's
    // `remaining: 0` forever, refusing to share while displaying a reset time
    // that had already passed: a message contradicting itself, with no way out
    // but backgrounding the app. `useQuotas` refetches AT the boundary, which
    // is what this asserts.
    //
    // Past MIN_QUOTA_REFETCH_MS (30s), not just past the 5s boundary: the hook
    // floors its interval so a past-due `resets_at` cannot become a 1ms hot
    // loop against /me. In production the boundary is midnight UTC and hours
    // away, so the floor only ever binds in the last 30 seconds — being at most
    // half a minute late to a daily reset is not a cost worth a polling storm.
    await jest.advanceTimersByTimeAsync(31_000);

    await waitFor(() => expect(screen.queryByTestId('share-quota-reached')).toBeNull());
  } finally {
    jest.useRealTimers();
  }
});

it('does not hammer /me when the reset time is already in the past', async () => {
  jest.useFakeTimers();
  try {
    let meCalls = 0;
    mock.onGet('/me').reply(() => {
      meCalls += 1;
      // A boundary two days BEHIND us — reachable with no bug on our side: a
      // device clock ahead of the server's, or a snapshot restored from disk
      // long after its window closed.
      return [
        200,
        {
          ...meWithQuota(0),
          meta: { quotas: { ...meWithQuota(0).meta.quotas, resets_at: '2020-01-01T00:00:00+00:00' } },
        },
      ];
    });

    render(<ShareScreen />, { wrapper: Providers });
    await waitFor(() => expect(meCalls).toBeGreaterThan(0));

    const afterMount = meCalls;
    await jest.advanceTimersByTimeAsync(60_000);

    // `refetchInterval` is a REPEATING poll, not a one-shot. Answering a
    // past-due boundary with "refetch immediately" polls a thousand times a
    // second for as long as it stays past-due. Floored at 30s, a minute buys a
    // couple of refetches — not hundreds.
    expect(meCalls - afterMount).toBeLessThanOrEqual(3);
  } finally {
    jest.useRealTimers();
  }
});

it('stays out of the way while the user has allowance left', async () => {
  mock.onGet('/me').reply(200, meWithQuota(7));

  render(<ShareScreen />, { wrapper: Providers });

  await screen.findByTestId('share-submit');
  // No "you have 7 left" nagging — a limit nobody is near is not news.
  expect(screen.queryByTestId('share-quota-reached')).toBeNull();
  expect(screen.getByTestId('share-submit').props.accessibilityState.disabled).toBe(false);
});

it('does not block sharing when the quota cannot be read', async () => {
  mock.onGet('/me').reply(500);

  render(<ShareScreen />, { wrapper: Providers });

  await screen.findByTestId('share-submit');
  // Fail OPEN. The server enforces the real limit; letting a failed /me lock
  // someone out of the app's core action would turn a monitoring blip into an
  // outage, and the worst case is one wasted 429.
  expect(screen.queryByTestId('share-quota-reached')).toBeNull();
  expect(screen.getByTestId('share-submit').props.accessibilityState.disabled).toBe(false);
});
