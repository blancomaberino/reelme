import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import StatusScreen from '../status';
import { reviewShare, shareDetail } from '@/test/share-fixtures';
import { api } from '@/api/client';

import { mockRouter } from '../../../../jest.setup';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.push.mockClear();
  mockRouter.replace.mockClear();
  mockRouter.params = { id: '1' };
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('forwards an editable review to the correction form', async () => {
  mock.onGet('/shares/1').reply(200, { data: reviewShare() });

  render(<StatusScreen />, { wrapper: Providers });

  await waitFor(() =>
    expect(mockRouter.replace).toHaveBeenCalledWith({ pathname: '/shares/[id]/review', params: { id: '1' } }),
  );
});

it('shows the published place and jumps to the map', async () => {
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({ status: 'published', place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 }, places: [{ id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 }] }),
  });

  render(<StatusScreen />, { wrapper: Providers });

  expect(await screen.findByText('Clara Café')).toBeOnTheScreen();
  fireEvent.press(screen.getByText('View on map'));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/place/[slug]', params: { slug: '9' } });
});

it('renders the mapped copy + actions for each failure reason', async () => {
  const cases = [
    { code: 'fetch_unavailable', title: 'Couldn’t load the post', retry: true, addManually: true },
    { code: 'fetch_auth_required', title: 'This post is private', retry: false, addManually: true },
    { code: 'quota_exhausted', title: 'Out of analyses', retry: false, addManually: false },
    { code: 'mystery_reason', title: 'Something went wrong', retry: true, addManually: false }, // generic fallback
  ] as const;

  for (const tc of cases) {
    qc.clear();
    mock.reset();
    mock.onGet('/shares/1').reply(200, {
      data: shareDetail({ status: 'failed', failure: { code: tc.code, step: null, message: 'x', manual_fallback: false } }),
    });

    const view = render(<StatusScreen />, { wrapper: Providers });
    expect(await screen.findByText(tc.title)).toBeOnTheScreen();
    expect(screen.queryByText('Try again') != null).toBe(tc.retry);
    expect(screen.queryByText('Add by hand') != null).toBe(tc.addManually);
    view.unmount();
  }
});

it('does NOT auto-navigate a review with no extraction (fetch failure), and retries in place', async () => {
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({ status: 'review', failure: { code: 'fetch_unavailable', step: 'fetch', message: 'x', manual_fallback: true } }),
  });
  mock.onPost('/shares/1/retry').reply(202, {});

  render(<StatusScreen />, { wrapper: Providers });

  expect(await screen.findByText('Couldn’t load the post')).toBeOnTheScreen();
  // review WITHOUT an editable extraction stays put — no hand-off to the form.
  expect(mockRouter.replace).not.toHaveBeenCalled();

  fireEvent.press(screen.getByText('Try again'));
  await waitFor(() => expect(mock.history.post.some((r) => r.url === '/shares/1/retry')).toBe(true));
});
