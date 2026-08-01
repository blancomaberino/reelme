import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import { api } from '@/api/client';
import { useLogin } from '@/api/hooks/useAuth';
import { clearToken, getToken } from '@/api/token';
import { TwoFactorRequiredError } from '@/api/types';
import { useSessionStore } from '@/stores/session';

/**
 * Login's two response shapes (T-068).
 *
 * The interesting case is the second one: `/auth/login` answers a 2FA account
 * with a challenge and NO token. Before this was handled, that payload flowed
 * straight into `onAuthenticated`, which destructured a `token` that wasn't
 * there and persisted `undefined` — leaving the app looking signed in with no
 * credential at all.
 */

let mock: AxiosMockAdapter;
let qc: QueryClient;

const ME = { id: 1, username: 'ana', name: 'Ana', email: 'ana@example.com' };

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(async () => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } } });
  mock = new AxiosMockAdapter(api);
  useSessionStore.setState({ user: null });
  // The SecureStore mock is a module-level Map, so a token stored by an earlier
  // test survives into this one — and would make "nothing was persisted" pass
  // or fail on test order rather than on behaviour.
  await clearToken();
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

it('stores the token and the user on an ordinary login', async () => {
  mock.onPost('/auth/login').reply(200, { data: { token: 'tok-123', user: ME } });

  const { result } = renderHook(() => useLogin(), { wrapper });
  result.current.mutate({ email: 'ana@example.com', password: 'secret123!' });

  await waitFor(() => expect(result.current.isSuccess).toBe(true));
  expect(await getToken()).toBe('tok-123');
  expect(useSessionStore.getState().user).toEqual(ME);
});

it('raises a typed error for a 2FA challenge instead of persisting a missing token', async () => {
  mock.onPost('/auth/login').reply(200, {
    data: { two_factor_required: true, challenge_token: 'challenge-abc' },
  });

  const { result } = renderHook(() => useLogin(), { wrapper });
  result.current.mutate({ email: 'ana@example.com', password: 'secret123!' });

  await waitFor(() => expect(result.current.isError).toBe(true));

  const error = result.current.error;
  expect(error).toBeInstanceOf(TwoFactorRequiredError);
  // The challenge rides along so the 2FA screen can complete the exchange once
  // that half ships.
  expect((error as TwoFactorRequiredError).challengeToken).toBe('challenge-abc');

  // Nothing half-written: no credential, no session user.
  expect(await getToken()).toBeNull();
  expect(useSessionStore.getState().user).toBeNull();
});
