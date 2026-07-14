import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, fireEvent, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import VerifyEmailScreen from '../verify-email';
import { api } from '@/api/client';
import { clearToken, getToken } from '@/api/token';
import type { Me } from '@/api/types';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../../../jest.setup';

const VERIFIED_USER: Me = {
  id: '1', name: 'V', username: 'v', email: 'v@example.com', avatar_path: null, bio: null,
  birthdate: null, age: null, favorite_topics: [], favorite_foods: [], is_influencer: false,
  is_restaurant_owner: false, is_admin: false, is_public: true, preferred_analysis_model: null,
  stripe_connect_onboarded: false, email_verified_at: '2026-07-14T00:00:00Z', created_at: null,
};

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(async () => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.params = { email: 'v@example.com' };
  mockRouter.replace.mockClear();
  await clearToken();
  useSessionStore.setState({ user: null, status: 'guest' });
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('confirms with the code, logs in, and navigates to the map', async () => {
  let body: unknown = null;
  mock.onPost('/auth/verify-email').reply((config) => {
    body = JSON.parse(config.data as string);
    return [200, { data: { token: 'tok_verified', user: VERIFIED_USER }, meta: {} }];
  });

  render(<VerifyEmailScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Code'), '123456');
  fireEvent.press(screen.getByText('Confirm'));

  await waitFor(() => expect(useSessionStore.getState().status).toBe('authed'));
  expect(body).toMatchObject({ email: 'v@example.com', code: '123456' });
  expect(mockRouter.replace).toHaveBeenCalledWith('/(main)/map');
  expect(await getToken()).toBe('tok_verified');
});

it('resends the code', async () => {
  mock.onPost('/auth/verify-email').reply(200, { data: {}, meta: {} });
  let resent = false;
  mock.onPost('/auth/resend-verification').reply((config) => {
    resent = JSON.parse(config.data as string).email === 'v@example.com';
    return [200, { data: { status: 'sent' }, meta: {} }];
  });

  render(<VerifyEmailScreen />, { wrapper: Providers });
  fireEvent.press(screen.getByText('Resend code'));

  await waitFor(() => expect(resent).toBe(true));
});
