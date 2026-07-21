import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import ShareScreen from '../share';
import { api } from '@/api/client';
import type { ShareDetail } from '@/api/shares';
import { useUiStore } from '@/stores/ui';

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
    places: [],
    pending_place_count: 0,
    pending_places: [],
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
  mockRouter.replace.mockClear();
  mockRouter.params = {};
  // No share staged in the UI store unless a test opts in (auth-gate resume).
  useUiStore.setState({ pendingShare: null });
  // Recent-shares list: default to empty so the section stays hidden unless a
  // test opts in.
  mock.onGet('/shares').reply(200, { data: [] });
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

  // T-076: a single clean publish auto-opens the place detail (no manual tap).
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
  expect(sent).toMatchObject({ url: 'https://instagram.com/reel/abc', shared_via: 'share_sheet' });
});

it('resumes a share staged in the UI store (unauthenticated share surviving login)', async () => {
  // The root ShareIntentRedirect stages the payload before the sign-in redirect
  // so it isn't lost to the auth gate; post-login the ingest screen consumes it.
  useUiStore.setState({ pendingShare: { url: 'https://tiktok.com/@a/video/1', text: '' } });
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
  expect(sent).toMatchObject({ url: 'https://tiktok.com/@a/video/1', shared_via: 'share_sheet' });
  // The staged share is consumed exactly once (not left to re-fire on resume).
  expect(useUiStore.getState().pendingShare).toBeNull();
});

it('shows a platform badge for a recognized pasted link', async () => {
  render(<ShareScreen />, { wrapper: Providers });

  // Nothing typed → no badge.
  expect(screen.queryByText('Instagram')).not.toBeOnTheScreen();

  fireEvent.changeText(screen.getByLabelText('Link'), 'https://www.instagram.com/reel/x/');
  expect(await screen.findByText('Instagram')).toBeOnTheScreen();

  // A different host re-derives the badge; an unrecognized one hides it.
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://youtu.be/abc');
  expect(await screen.findByText('YouTube')).toBeOnTheScreen();
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://example.com/x');
  expect(screen.queryByText('YouTube')).not.toBeOnTheScreen();
});

it('notes a re-shared post as already added (idempotent replay), not an error', async () => {
  mock.onPost('/shares').reply(202, { data: { id: '1', status: 'published' }, meta: { idempotent_replay: true } });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({ id: '1', status: 'published', place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 } }),
  });

  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  expect(await screen.findByText('You already added this one.')).toBeOnTheScreen();
  expect(screen.getByText('Clara Café')).toBeOnTheScreen();
});

it('offers Retry on a failed share and re-runs the pipeline', async () => {
  mock.onPost('/shares').reply(201, { data: shareDetail({ id: '1', status: 'pending' }) });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({
      id: '1',
      status: 'failed',
      failure: { code: 'ollama_unreachable', step: 'analyze', message: 'Analysis failed. Please try again.', manual_fallback: false },
    }),
  });
  mock.onPost('/shares/1/retry').reply(202, {});

  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  expect(await screen.findByText('Analysis failed. Please try again.')).toBeOnTheScreen();
  fireEvent.press(screen.getByRole('button', { name: 'Try again' }));

  await waitFor(() => expect(mock.history.post.some((r) => r.url === '/shares/1/retry')).toBe(true));
});

it('lists recent shares with a status pill and taps a published one through', async () => {
  mock.onGet('/shares').reply(200, {
    data: [
      shareDetail({ id: '5', status: 'analyzing', source_post: { ...shareDetail({}).source_post, caption: 'ramen spot' } }),
      shareDetail({ id: '6', status: 'published', place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 } }),
    ],
  });

  render(<ShareScreen />, { wrapper: Providers });

  expect(await screen.findByText('Recent shares')).toBeOnTheScreen();
  expect(screen.getByText('Analyzing')).toBeOnTheScreen();
  expect(screen.getByText('Published')).toBeOnTheScreen();

  fireEvent.press(screen.getByText('Clara Café'));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/place/[slug]', params: { slug: '9' } });
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

it('does NOT auto-open a detail for a multi-place publish — it lists them to tap through (T-076)', async () => {
  mock.onPost('/shares').reply(201, { data: shareDetail({ id: '1', status: 'pending' }) });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({
      id: '1',
      status: 'published',
      places: [
        { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 },
        { id: '10', name: 'Bar Tabaré', lat: -34.9, lng: -56.2 },
      ],
    }),
  });

  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  // Both venues are listed and none is auto-opened (no venue lost).
  expect(await screen.findByText('Clara Café')).toBeOnTheScreen();
  expect(screen.getByText('Bar Tabaré')).toBeOnTheScreen();
  expect(mockRouter.push).not.toHaveBeenCalled();

  // Tapping one still routes through to its detail.
  fireEvent.press(screen.getByText('Bar Tabaré'));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/place/[slug]', params: { slug: '10' } });
});

it('does NOT auto-open when a single publish still has venues in review (T-076)', async () => {
  mock.onPost('/shares').reply(201, { data: shareDetail({ id: '1', status: 'pending' }) });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({
      id: '1',
      status: 'published',
      place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 },
      pending_place_count: 1,
      pending_places: [{ index: 1, name: 'Mystery Spot', reason: 'geocode_failed', candidates: [] }],
    }),
  });

  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  // The card stays so the pending venue can be resolved — no abrupt jump.
  expect(await screen.findByText('Clara Café')).toBeOnTheScreen();
  expect(mockRouter.push).not.toHaveBeenCalled();

  // The explicit "View place" button is still available to open it.
  fireEvent.press(screen.getByRole('button', { name: 'View place' }));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/place/[slug]', params: { slug: '9' } });
});
