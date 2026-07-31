import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, fireEvent, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import LoginScreen from '../login';
import { api } from '@/api/client';
import { useSessionStore } from '@/stores/session';
import { useSettingsStore } from '@/stores/settings';

/**
 * Signing in with no connection (T-103). "Something went wrong. Please try
 * again." is the wrong instruction here — there is nothing to try again until
 * the network is back, and the user's credentials were never the problem. The
 * copy also has to be in their language, which the old hard-coded English
 * string never was.
 */

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

/** Fill in and submit the form. Labels differ by locale, so pass them in. */
function submitLogin({ email = 'Email', password = 'Password', submit = 'Log in' } = {}) {
  render(<LoginScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText(email), 'ada@example.com');
  fireEvent.changeText(screen.getByLabelText(password), 'secret123!');
  fireEvent.press(screen.getByText(submit));
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { mutations: { retry: 0, networkMode: 'always' } } });
  mock = new AxiosMockAdapter(api);
  useSessionStore.setState({ user: null, status: 'guest' });
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('tells the user the connection is the problem, not their credentials', async () => {
  mock.onPost('/auth/login').networkError();

  submitLogin();

  await waitFor(() => expect(screen.getByText('No connection. Check your network and try again.')).toBeTruthy());
  expect(screen.queryByText('Something went wrong. Please try again.')).toBeNull();
  expect(useSessionStore.getState().status).toBe('guest');
});

it('says it in Spanish for a Spanish user (the default locale)', async () => {
  useSettingsStore.setState({ locale: 'es' });
  mock.onPost('/auth/login').networkError();

  submitLogin({ email: 'Correo', password: 'Contraseña', submit: 'Iniciar sesión' });

  await waitFor(() => expect(screen.getByText('Sin conexión. Revisa tu red e inténtalo de nuevo.')).toBeTruthy());
});

it('still shows the generic message when the server itself fails', async () => {
  mock.onPost('/auth/login').reply(500);

  submitLogin();

  await waitFor(() => expect(screen.getByText('Something went wrong. Please try again.')).toBeTruthy());
});
