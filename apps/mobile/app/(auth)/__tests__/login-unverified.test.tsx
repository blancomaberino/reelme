import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, fireEvent, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import LoginScreen from '../login';
import { api } from '@/api/client';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../../../jest.setup';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.push.mockClear();
  useSessionStore.setState({ user: null, status: 'guest' });
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('routes an unconfirmed login to the verify screen, prefilled', async () => {
  mock.onPost('/auth/login').reply(403, {
    error: {
      code: 'email_not_verified',
      message: 'Confirmá tu correo antes de iniciar sesión.',
      details: { email: 'v@example.com' },
      request_id: 'req_1',
    },
  });

  render(<LoginScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Email'), 'v@example.com');
  fireEvent.changeText(screen.getByLabelText('Password'), 'secret123!');
  fireEvent.press(screen.getByText('Log in'));

  await waitFor(() =>
    expect(mockRouter.push).toHaveBeenCalledWith(
      expect.objectContaining({
        pathname: '/(auth)/verify-email',
        params: expect.objectContaining({ email: 'v@example.com' }),
      }),
    ),
  );
  // Not authed — no token was issued.
  expect(useSessionStore.getState().status).toBe('guest');
});
