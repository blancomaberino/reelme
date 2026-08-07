import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import ShareScreen from '../share';
import { api } from '@/api/client';
import { useSessionStore } from '@/stores/session';

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
});

afterEach(() => mock.restore());

it('says the limit is reached and refuses to send', async () => {
  mock.onGet('/me').reply(200, meWithQuota(0));
  mock.onPost('/shares').reply(201, { data: {}, meta: {} });

  render(<ShareScreen />, { wrapper: Providers });

  const notice = await screen.findByTestId('share-quota-reached');
  expect(notice).toBeOnTheScreen();
  expect(screen.getByTestId('share-submit').props.accessibilityState.disabled).toBe(true);

  // A disabled Pressable still receives the synthetic press in RNTL, which is
  // the regression worth pinning: the request must not go out.
  fireEvent.press(screen.getByTestId('share-submit'));
  await waitFor(() => expect(mock.history.post).toHaveLength(0));
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
