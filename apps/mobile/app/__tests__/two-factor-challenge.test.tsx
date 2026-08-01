import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import TwoFactorChallengeScreen from '../(auth)/two-factor';
import { api } from '@/api/client';
import { clearToken, getToken } from '@/api/token';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../../jest.setup';

/**
 * The login 2FA challenge screen (T-068).
 *
 * What matters here is that the screen ESTABLISHES A SESSION correctly — it is
 * the only place outside `useAuth` that does — and that the recovery path is
 * reachable, because it is what a user with a lost phone needs and the one
 * thing they cannot discover by guessing.
 */

let mock: AxiosMockAdapter;
let qc: QueryClient;

const ME = { id: 7, username: 'ana', name: 'Ana', email: 'ana@example.com' };

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(async () => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: 0 } } });
  mock = new AxiosMockAdapter(api);
  useSessionStore.setState({ user: null });
  mockRouter.replace.mockClear();
  // The suite's single canonical expo-router mock reads route params from here.
  mockRouter.params = { challenge: 'challenge-abc' };
  // The SecureStore mock is a module-level Map, so a token from an earlier test
  // survives into this one and would make "nothing was stored" order-dependent.
  await clearToken();
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

it('exchanges a TOTP code for a session and lands on the map', async () => {
  mock.onPost('/auth/two-factor-challenge').reply(200, { data: { token: 'tok-2fa', user: ME } });

  render(<TwoFactorChallengeScreen />, { wrapper });
  fireEvent.changeText(screen.getByTestId('code-input'), '123456');
  fireEvent.press(screen.getByTestId('challenge-submit'));

  await waitFor(() => expect(mockRouter.replace).toHaveBeenCalledWith('/(main)/map'));

  // The session is fully established — token, store, and the `me` cache entry
  // that the offline cold start restores from (T-103).
  expect(await getToken()).toBe('tok-2fa');
  expect(useSessionStore.getState().user).toEqual(ME);
  expect(qc.getQueryData(['me'])).toEqual(ME);
});

it('sends the challenge token and the device name with the code', async () => {
  mock.onPost('/auth/two-factor-challenge').reply(200, { data: { token: 't', user: ME } });

  render(<TwoFactorChallengeScreen />, { wrapper });
  fireEvent.changeText(screen.getByTestId('code-input'), '123456');
  fireEvent.press(screen.getByTestId('challenge-submit'));

  await waitFor(() => expect(mock.history.post).toHaveLength(1));
  const body = JSON.parse(mock.history.post[0].data);
  expect(body.challenge_token).toBe('challenge-abc');
  expect(body.code).toBe('123456');
  // Same device_name as the password login, or the pre-2FA token for this
  // device survives instead of being replaced.
  expect(body.device_name).toBeTruthy();
});

it('will not submit a half-typed code', async () => {
  render(<TwoFactorChallengeScreen />, { wrapper });
  fireEvent.changeText(screen.getByTestId('code-input'), '123');
  fireEvent.press(screen.getByTestId('challenge-submit'));

  expect(mock.history.post).toHaveLength(0);
});

it('switches to a recovery code and sends it under the right field', async () => {
  mock.onPost('/auth/two-factor-challenge').reply(200, { data: { token: 't', user: ME } });

  render(<TwoFactorChallengeScreen />, { wrapper });
  fireEvent.press(screen.getByTestId('toggle-recovery'));
  fireEvent.changeText(screen.getByTestId('recovery-input'), 'ABCDEFGHJK-MNPQRTUVWX');
  fireEvent.press(screen.getByTestId('challenge-submit'));

  await waitFor(() => expect(mock.history.post).toHaveLength(1));
  const body = JSON.parse(mock.history.post[0].data);
  // Under `recovery_code`, NOT `code` — the API rejects a 21-character `code`
  // on validation before it ever compares anything.
  expect(body.recovery_code).toBe('ABCDEFGHJK-MNPQRTUVWX');
  expect(body.code).toBeUndefined();
});

it('keeps the user on the screen and clears the field when the code is wrong', async () => {
  mock.onPost('/auth/two-factor-challenge').reply(422, {
    error: { code: 'validation_failed', message: 'nope', details: { code: ['That code is not valid.'] } },
  });

  render(<TwoFactorChallengeScreen />, { wrapper });
  fireEvent.changeText(screen.getByTestId('code-input'), '000000');
  fireEvent.press(screen.getByTestId('challenge-submit'));

  await waitFor(() => expect(screen.getByTestId('code-input').props.value).toBe(''));
  expect(mockRouter.replace).not.toHaveBeenCalled();
  expect(await getToken()).toBeNull();
});
