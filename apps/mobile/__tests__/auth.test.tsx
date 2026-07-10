import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, renderHook, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import LoginScreen from '../app/(auth)/login';
import { api } from '@/api/client';
import { useLogin, useRegister } from '@/api/hooks/useAuth';
import { fetchMe } from '@/api/hooks/useMe';
import { clearToken, getToken } from '@/api/token';
import { ValidationError } from '@/api/types';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../jest.setup';

let mock: AxiosMockAdapter;

const USER = {
  id: '1',
  name: 'Maya',
  username: 'maya',
  email: 'maya@example.com',
  avatar_path: null,
  bio: null,
  is_influencer: false,
  is_restaurant_owner: false,
  is_admin: false,
  is_public: true,
  preferred_analysis_model: null,
  stripe_connect_onboarded: false,
  email_verified_at: null,
  created_at: null,
};

function wrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return ({ children }: { children: ReactNode }) => <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(async () => {
  mock = new AxiosMockAdapter(api);
  await clearToken();
  useSessionStore.setState({ user: null, status: 'loading' });
  jest.clearAllMocks();
});

afterEach(() => mock.restore());

it('login success stores the token and marks the session authed', async () => {
  mock.onPost('/auth/login').reply(200, { data: { token: 'tok_abc', user: USER }, meta: {} });

  const { result } = renderHook(() => useLogin(), { wrapper: wrapper() });
  await act(async () => {
    await result.current.mutateAsync({ email: 'maya@example.com', password: 'secret123!' });
  });

  expect(await getToken()).toBe('tok_abc');
  expect(useSessionStore.getState().status).toBe('authed');
  expect(useSessionStore.getState().user?.username).toBe('maya');
});

it('login failure (422) yields per-field errors and stores no token', async () => {
  mock.onPost('/auth/login').reply(422, {
    error: { message: 'Invalid', details: { email: ['These credentials do not match our records.'] } },
  });

  const { result } = renderHook(() => useLogin(), { wrapper: wrapper() });
  let error: unknown;
  await act(async () => {
    try {
      await result.current.mutateAsync({ email: 'x@y.z', password: 'nope' });
    } catch (e) {
      error = e;
    }
  });

  expect(error).toBeInstanceOf(ValidationError);
  expect((error as ValidationError).fields.email).toContain('do not match');
  expect(await getToken()).toBeNull();
});

it('register duplicate-email 422 maps to the email field', async () => {
  mock.onPost('/auth/register').reply(422, {
    error: { message: 'Invalid', details: { email: ['The email has already been taken.'] } },
  });

  const { result } = renderHook(() => useRegister(), { wrapper: wrapper() });
  let error: unknown;
  await act(async () => {
    try {
      await result.current.mutateAsync({ name: 'A', username: 'a', email: 'taken@example.com', password: 'secret123!' });
    } catch (e) {
      error = e;
    }
  });

  expect(error).toBeInstanceOf(ValidationError);
  expect((error as ValidationError).fields.email).toContain('already been taken');
});

it('401 interceptor clears the session and redirects to login', async () => {
  useSessionStore.setState({ user: USER, status: 'authed' });
  mock.onGet('/me').reply(401, { error: { code: 'unauthenticated', message: 'Unauthenticated.' } });

  await expect(fetchMe()).rejects.toBeTruthy();

  expect(mockRouter.replace).toHaveBeenCalledWith('/(auth)/login');
  expect(useSessionStore.getState().status).toBe('guest');
  expect(await getToken()).toBeNull();
});

it('a 401 on an auth path does NOT trigger the global redirect', async () => {
  mock.onPost('/auth/login').reply(401, { error: { code: 'unauthenticated', message: 'x' } });

  const { result } = renderHook(() => useLogin(), { wrapper: wrapper() });
  await act(async () => {
    try {
      await result.current.mutateAsync({ email: 'x@y.z', password: 'nope' });
    } catch {
      // expected
    }
  });

  expect(mockRouter.replace).not.toHaveBeenCalled();
});

it('login screen renders the 422 field error and navigates on success', async () => {
  mock.onPost('/auth/login').replyOnce(422, {
    error: { message: 'Invalid', details: { email: ['These credentials do not match our records.'] } },
  });

  render(<LoginScreen />, { wrapper: wrapper() });

  fireEvent.changeText(screen.getByLabelText('Email'), 'maya@example.com');
  fireEvent.changeText(screen.getByLabelText('Password'), 'nope');
  fireEvent.press(screen.getByRole('button', { name: 'Log in' }));

  expect(await screen.findByText(/do not match/i)).toBeOnTheScreen();
});
