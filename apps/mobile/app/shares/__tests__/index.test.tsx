import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import MySharesScreen from '../index';
import { shareDetail } from '@/test/share-fixtures';
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
  mockRouter.push.mockClear();
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('routes a published share to its pin and an in-review share to the status screen', async () => {
  mock.onGet('/shares').reply(200, {
    data: [
      shareDetail({ id: '20', status: 'review', source_post: { id: '2', platform: 'instagram', url: null, author_handle: null, caption: 'A place under review', fetch_status: 'ok' } }),
      shareDetail({ id: '21', status: 'published', place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 } }),
    ],
  });

  render(<MySharesScreen />, { wrapper: Providers });

  // published → place page
  fireEvent.press(await screen.findByText('Clara Café'));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/place/[slug]', params: { slug: '9' } });

  // in-review → status screen (which forwards to the correction form)
  fireEvent.press(screen.getByText('A place under review'));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/shares/[id]/status', params: { id: '20' } });
});

it('shows an empty state when there are no shares', async () => {
  mock.onGet('/shares').reply(200, { data: [] });

  render(<MySharesScreen />, { wrapper: Providers });

  expect(await screen.findByText('Nothing shared yet.')).toBeOnTheScreen();
});
