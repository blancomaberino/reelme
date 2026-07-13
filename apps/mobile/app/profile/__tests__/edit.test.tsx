import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import EditProfileScreen from '../edit';
import { api } from '@/api/client';
import type { Me } from '@/api/types';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../../../jest.setup';

let mock: AxiosMockAdapter;
let qc: QueryClient;

const ME: Me = {
  id: '1',
  name: 'Old Name',
  username: 'marce',
  email: 'm@one.pet',
  avatar_path: null,
  bio: null,
  birthdate: null,
  age: null,
  favorite_topics: ['ramen'],
  favorite_foods: [],
  is_influencer: false,
  is_restaurant_owner: false,
  is_admin: false,
  is_public: true,
  preferred_analysis_model: null,
  stripe_connect_onboarded: false,
  email_verified_at: null,
  created_at: null,
};

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.back.mockClear();
  useSessionStore.setState({ status: 'authed', user: ME });
});
afterEach(() => {
  mock.restore();
  qc.clear();
  useSessionStore.setState({ status: 'guest', user: null });
});

it('prefills from the session, edits fields + tags, and PATCHes /me', async () => {
  let sent: Record<string, unknown> = {};
  mock.onPatch('/me').reply((cfg) => {
    sent = JSON.parse(cfg.data);
    return [200, { data: { user: { ...ME, name: 'Marcelo', favorite_topics: ['ramen', 'coffee'] } } }];
  });

  render(<EditProfileScreen />, { wrapper: Providers });

  // Prefilled existing topic chip.
  expect(screen.getByText('ramen')).toBeOnTheScreen();

  fireEvent.changeText(screen.getByLabelText('Name'), 'Marcelo');
  fireEvent.changeText(screen.getByLabelText('Date of birth'), '1990-05-20');

  // Add a topic via the inline input.
  fireEvent.changeText(screen.getByPlaceholderText('Add a topic (e.g. ramen)'), 'coffee');
  fireEvent.press(screen.getAllByLabelText('Add')[0]);
  expect(screen.getByText('coffee')).toBeOnTheScreen();

  fireEvent.press(screen.getByRole('button', { name: 'Save' }));

  await waitFor(() => expect(mockRouter.back).toHaveBeenCalled());
  expect(sent).toMatchObject({
    name: 'Marcelo',
    birthdate: '1990-05-20',
    favorite_topics: ['ramen', 'coffee'],
  });
  // Session mirrors the fresh user.
  expect(useSessionStore.getState().user?.name).toBe('Marcelo');
});

it('removes a tag chip when tapped', async () => {
  mock.onPatch('/me').reply(200, { data: { user: ME } });
  render(<EditProfileScreen />, { wrapper: Providers });

  fireEvent.press(screen.getByLabelText('ramen ✕'));
  expect(screen.queryByText('ramen')).toBeNull();
});
