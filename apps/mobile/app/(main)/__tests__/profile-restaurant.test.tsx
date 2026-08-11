import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import ProfileScreen from '../profile';
import { api } from '@/api/client';
import type { Me } from '@/api/types';
import { useSessionStore } from '@/stores/session';
import { makeMe } from '@/test/me-fixture';
import { mockRouter } from '../../../jest.setup';

/**
 * The Profile entry point into the restaurant surface (T-042).
 *
 * The rule: the row exists only for a user whose place claim was VERIFIED.
 * `is_restaurant_owner` is set by that approval (T-041), so gating on it is
 * gating on the same fact the API's OfferPolicy checks — a diner tapping
 * through would land on a screen where every action 403s.
 */

let qc: QueryClient;
let mock: AxiosMockAdapter;

function me(overrides: Partial<Me> = {}): Me {
  return makeMe({
    email_verified_at: '2026-01-01T00:00:00Z',
    created_at: '2026-01-01T00:00:00Z',
    ...overrides,
  });
}

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  // `pagination` is NOT optional padding: useNotifications' getNextPageParam
  // reads meta.pagination.next_cursor on every render, so a fixture without
  // it throws inside the screen the moment ANY other query re-renders it.
  mock
    .onGet('/notifications')
    .reply(200, { data: [], meta: { unread_count: 0, pagination: { next_cursor: null } } });
  mockRouter.push.mockClear();
});

afterEach(() => {
  mock.restore();
  qc.clear();
  useSessionStore.setState({ user: null, status: 'guest' });
});

it('hides the restaurant row from a user with no verified claim', () => {
  useSessionStore.setState({ user: me(), status: 'authed' });

  render(<ProfileScreen />, { wrapper });

  expect(screen.queryByTestId('profile-restaurant')).toBeNull();
});

it('shows it to a verified operator and routes to their offers', () => {
  useSessionStore.setState({ user: me({ is_restaurant_owner: true }), status: 'authed' });

  render(<ProfileScreen />, { wrapper });
  fireEvent.press(screen.getByTestId('profile-restaurant'));

  expect(mockRouter.push).toHaveBeenCalledWith('/restaurant/offers');
});
