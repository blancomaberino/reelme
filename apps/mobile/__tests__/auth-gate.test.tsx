import { render, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';

import RootLayout from '../app/_layout';
import { api } from '@/api/client';
import { clearToken, getToken, setToken } from '@/api/token';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../jest.setup';

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

let mock: AxiosMockAdapter;

beforeEach(async () => {
  mock = new AxiosMockAdapter(api);
  await clearToken();
  useSessionStore.setState({ user: null, status: 'loading' });
  jest.clearAllMocks();
});

afterEach(() => mock.restore());

describe('auth gate (root layout bootstrap)', () => {
  it('no stored token → resolves to guest', async () => {
    render(<RootLayout />);

    await waitFor(() => expect(useSessionStore.getState().status).toBe('guest'));
    expect(useSessionStore.getState().user).toBeNull();
  });

  it('valid stored token → hydrates from /me and marks authed', async () => {
    await setToken('tok_boot');
    mock.onGet('/me').reply(200, { data: { user: USER }, meta: {} });

    render(<RootLayout />);

    await waitFor(() => expect(useSessionStore.getState().status).toBe('authed'));
    expect(useSessionStore.getState().user?.username).toBe('maya');
  });

  it('stale stored token (401 on /me) → clears token, goes guest, and does NOT redirect during bootstrap', async () => {
    await setToken('tok_stale');
    mock.onGet('/me').reply(401, { error: { code: 'unauthenticated', message: 'Unauthenticated.' } });

    render(<RootLayout />);

    await waitFor(() => expect(useSessionStore.getState().status).toBe('guest'));
    expect(await getToken()).toBeNull();
    // The gate + index own navigation while status is 'loading' — the interceptor
    // must not race them with its own replace('/(auth)/login').
    expect(mockRouter.replace).not.toHaveBeenCalled();
  });
});
