import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, fireEvent, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import FollowersScreen from '../[username]/followers';
import { api } from '@/api/client';

import { mockRouter } from '../../../jest.setup';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.params = { username: 'alice' };
  mockRouter.push.mockClear();
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('lists followers and navigates to a follower profile on tap', async () => {
  mock.onGet('/users/alice/followers').reply(200, {
    data: [
      { id: '1', user: { id: '3', username: 'carol', name: 'Carol', avatar_path: null } },
      { id: '2', user: null }, // private follower — shown but not tappable
    ],
    meta: { pagination: { next_cursor: null } },
  });

  render(<FollowersScreen />, { wrapper: Providers });

  expect(await screen.findByText('Carol')).toBeOnTheScreen();
  fireEvent.press(screen.getByText('Carol'));

  await waitFor(() =>
    expect(mockRouter.push).toHaveBeenCalledWith(
      expect.objectContaining({ pathname: '/users/[username]', params: { username: 'carol' } }),
    ),
  );
});
