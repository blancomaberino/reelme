import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, fireEvent, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import LoginScreen from '../login';
import RegisterScreen from '../register';
import VerifyEmailScreen from '../verify-email';
import { api } from '@/api/client';
import { useSessionStore } from '@/stores/session';
import { useUiStore } from '@/stores/ui';

import { mockRouter } from '../../../jest.setup';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.replace.mockClear();
  mockRouter.params = {};
  useSessionStore.setState({ user: null, status: 'guest' });
  useUiStore.setState({ pendingShare: null });
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

const AUTH_OK = {
  data: { token: 'tok_1', user: { id: '1', name: 'Ada', username: 'ada', email: 'a@example.com' } },
};

it('shows the share banner and resumes on the ingest screen after login', async () => {
  useUiStore.setState({ pendingShare: { url: 'https://instagram.com/reel/x', text: '' } });
  mock.onPost('/auth/login').reply(200, AUTH_OK);

  render(<LoginScreen />, { wrapper: Providers });
  expect(screen.getByText('Sign in to add this place to your map.')).toBeOnTheScreen();

  fireEvent.changeText(screen.getByLabelText('Email'), 'a@example.com');
  fireEvent.changeText(screen.getByLabelText('Password'), 'secret123!');
  fireEvent.press(screen.getByText('Log in'));

  await waitFor(() => expect(mockRouter.replace).toHaveBeenCalledWith('/(main)/share'));
});

it('hides the banner and goes to the map when no share is staged', async () => {
  mock.onPost('/auth/login').reply(200, AUTH_OK);

  render(<LoginScreen />, { wrapper: Providers });
  expect(screen.queryByText('Sign in to add this place to your map.')).not.toBeOnTheScreen();

  fireEvent.changeText(screen.getByLabelText('Email'), 'a@example.com');
  fireEvent.changeText(screen.getByLabelText('Password'), 'secret123!');
  fireEvent.press(screen.getByText('Log in'));

  await waitFor(() => expect(mockRouter.replace).toHaveBeenCalledWith('/(main)/map'));
});

it('resumes on the ingest screen after registration (a first-time sharer has no account)', async () => {
  useUiStore.setState({ pendingShare: { url: 'https://instagram.com/reel/x', text: '' } });
  mock.onPost('/auth/register').reply(201, AUTH_OK);

  render(<RegisterScreen />, { wrapper: Providers });
  expect(screen.getByText('Sign in to add this place to your map.')).toBeOnTheScreen();

  fireEvent.changeText(screen.getByLabelText('Name'), 'Ada');
  fireEvent.changeText(screen.getByLabelText('Username'), 'ada');
  fireEvent.changeText(screen.getByLabelText('Email'), 'a@example.com');
  fireEvent.changeText(screen.getByLabelText('Password'), 'secret123!');
  fireEvent.press(screen.getByText('Create account'));

  await waitFor(() => expect(mockRouter.replace).toHaveBeenCalledWith('/(main)/share'));
});

it('resumes on the ingest screen after email verification', async () => {
  useUiStore.setState({ pendingShare: { url: 'https://instagram.com/reel/x', text: '' } });
  mockRouter.params = { email: 'a@example.com' };
  mock.onPost('/auth/verify-email').reply(200, AUTH_OK);

  render(<VerifyEmailScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Code'), '123456');
  fireEvent.press(screen.getByText('Confirm'));

  await waitFor(() => expect(mockRouter.replace).toHaveBeenCalledWith('/(main)/share'));
});
