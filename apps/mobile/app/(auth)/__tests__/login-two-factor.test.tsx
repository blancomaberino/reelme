import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import LoginScreen from '../login';
import { api } from '@/api/client';
import { getToken } from '@/api/token';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../../../jest.setup';

/**
 * Login → 2FA challenge (T-068).
 *
 * `/auth/login` answers a 2FA account with 200 and a challenge — not an error
 * status — so the success path has to recognise it. The danger is the opposite
 * of the unverified case above: a 200 that LOOKS like a session but carries no
 * token, which an unguarded handler would persist as `undefined`.
 */

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.push.mockClear();
  mockRouter.replace.mockClear();
  useSessionStore.setState({ user: null, status: 'guest' });
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

it('routes a 2FA account to the challenge screen, carrying the token', async () => {
  mock.onPost('/auth/login').reply(200, {
    data: { two_factor_required: true, challenge_token: 'challenge-abc' },
  });

  render(<LoginScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Email'), 'ana@example.com');
  fireEvent.changeText(screen.getByLabelText('Password'), 'secret123!');
  fireEvent.press(screen.getByText('Log in'));

  await waitFor(() =>
    expect(mockRouter.push).toHaveBeenCalledWith(
      expect.objectContaining({
        pathname: '/(auth)/two-factor',
        params: expect.objectContaining({ challenge: 'challenge-abc' }),
      }),
    ),
  );

  // A correct password alone must leave nothing behind: no session, and above
  // all no token — the 200 makes this the easy thing to get wrong.
  expect(useSessionStore.getState().status).toBe('guest');
  expect(await getToken()).toBeNull();
  expect(mockRouter.replace).not.toHaveBeenCalled();
});

it('still signs in normally when the account has no second factor', async () => {
  mock.onPost('/auth/login').reply(200, {
    data: { token: 'tok-plain', user: { id: 1, username: 'ana', name: 'Ana', email: 'ana@example.com' } },
  });

  render(<LoginScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Email'), 'ana@example.com');
  fireEvent.changeText(screen.getByLabelText('Password'), 'secret123!');
  fireEvent.press(screen.getByText('Log in'));

  await waitFor(() => expect(mockRouter.replace).toHaveBeenCalledWith('/(main)/map'));
  expect(mockRouter.push).not.toHaveBeenCalled();
});
