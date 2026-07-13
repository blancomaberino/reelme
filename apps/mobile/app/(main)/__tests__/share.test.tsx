import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import ShareScreen from '../share';
import { api } from '@/api/client';
import type { ShareDetail } from '@/api/shares';

import { mockRouter } from '../../../jest.setup';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function shareDetail(over: Partial<ShareDetail>): ShareDetail {
  return {
    id: '1',
    status: 'pending',
    status_history: [],
    source_post: {
      id: '1',
      platform: 'instagram',
      url: 'https://ig.com/reel/x',
      author_handle: null,
      caption: null,
      fetch_status: 'ok',
    },
    analysis: null,
    failure: null,
    place: null,
    ...over,
  };
}

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.push.mockClear();
  mockRouter.params = {};
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('submits a link, shows the published pin, and navigates to the place', async () => {
  mock.onPost('/shares').reply(201, { data: shareDetail({ id: '1', status: 'pending' }) });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({
      id: '1',
      status: 'published',
      place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 },
    }),
  });

  render(<ShareScreen />, { wrapper: Providers });

  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  expect(await screen.findByText('Pinned!')).toBeOnTheScreen();
  expect(screen.getByText('Clara Café')).toBeOnTheScreen();

  fireEvent.press(screen.getByRole('button', { name: 'View place' }));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/place/[slug]', params: { slug: '9' } });
});

it('fetches the real state when POST replays an already-published share (no false "failed")', async () => {
  // Idempotent replay: the 202 ack is terminal + stripped (place null). The
  // screen must still GET the full resource and render the published place,
  // not misread the stripped payload as a failure.
  mock.onPost('/shares').reply(202, { data: { id: '1', status: 'published' } });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({ id: '1', status: 'published', place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 } }),
  });

  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  expect(await screen.findByText('Pinned!')).toBeOnTheScreen();
  expect(screen.getByText('Clara Café')).toBeOnTheScreen();
});

it('auto-submits a link shared in from the iOS share sheet', async () => {
  // The root ShareIntentRedirect routes here with sharedUrl set; the screen
  // should POST it without any tap and drive to the published result.
  mockRouter.params = { sharedUrl: 'https://instagram.com/reel/abc' };
  let sent: Record<string, unknown> = {};
  mock.onPost('/shares').reply((cfg) => {
    sent = JSON.parse(cfg.data);
    return [201, { data: shareDetail({ id: '1', status: 'pending' }) }];
  });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({ id: '1', status: 'published', place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 } }),
  });

  render(<ShareScreen />, { wrapper: Providers });

  expect(await screen.findByText('Pinned!')).toBeOnTheScreen();
  expect(sent).toMatchObject({ url: 'https://instagram.com/reel/abc', shared_via: 'paste_url' });
});

it('shows a validation error and does not POST when both inputs are empty', async () => {
  render(<ShareScreen />, { wrapper: Providers });

  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  expect(await screen.findByText('Paste a link or a caption first.')).toBeOnTheScreen();
  expect(mock.history.post).toHaveLength(0);
});

it('surfaces a review outcome with the failure message', async () => {
  mock.onPost('/shares').reply(201, { data: shareDetail({ id: '1', status: 'pending' }) });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({
      id: '1',
      status: 'review',
      failure: { code: 'geocode_failed', step: 'resolve', message: "We couldn't pin this place.", manual_fallback: true },
    }),
  });

  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  expect(await screen.findByText('Needs a quick review')).toBeOnTheScreen();
  expect(screen.getByText("We couldn't pin this place.")).toBeOnTheScreen();
});
