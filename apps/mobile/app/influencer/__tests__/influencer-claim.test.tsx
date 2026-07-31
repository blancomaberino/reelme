import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import ClaimInfluencerScreen from '../[id]/claim';
import { api } from '@/api/client';
import type { InfluencerClaim } from '@/api/influencers';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../../../jest.setup';

/**
 * The two-door claim flow (T-038 backend, T-039 UI).
 *
 * The screen shows exactly one door at a time, and which one depends on server
 * state — a pending bio-code claim has to survive the user leaving the app to
 * go edit their bio, so the token is re-read on return rather than held in
 * component state. Getting that wrong loses a one-time code.
 */

let mock: AxiosMockAdapter;
let qc: QueryClient;

function claim(over: Partial<InfluencerClaim> = {}): InfluencerClaim {
  return {
    id: '1',
    influencer_id: '7',
    status: 'pending',
    method: 'bio_code',
    token: 'reelmap-verify-ABCD2345',
    expires_at: '2026-08-03T00:00:00Z',
    ...over,
  };
}

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.params = { id: '7' };
  mockRouter.back.mockClear();
  useSessionStore.setState({ user: null, status: 'authed' });
  mock.onGet('/influencers/7').reply(200, {
    data: {
      id: '7',
      platform: 'instagram',
      handle: 'reviewer',
      display_name: null,
      avatar_url: null,
      claimed: false,
      claimed_by: null,
      follower_count: 0,
      counters: { promoted_places: 0 },
    },
    meta: { viewer: { following: false, follow_id: null } },
  });
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

it('offers both doors when there is no claim yet', async () => {
  mock.onGet('/influencers/7/claim').reply(404);

  render(<ClaimInfluencerScreen />, { wrapper: Providers });

  expect(await screen.findByTestId('claim-methods')).toBeTruthy();
  expect(screen.getByText('I’ve linked this account')).toBeTruthy();
  expect(screen.getByText('Verify with a code')).toBeTruthy();
});

/**
 * A viewer who has never claimed anything 404s here, which is the COMMON case —
 * it must read as "no claim yet", not as a broken screen.
 */
it('treats a missing claim as "not started", not as an error', async () => {
  mock.onGet('/influencers/7/claim').reply(404);

  render(<ClaimInfluencerScreen />, { wrapper: Providers });

  expect(await screen.findByTestId('claim-methods')).toBeTruthy();
  expect(screen.queryByText('Something went wrong. Please try again.')).toBeNull();
});

it('shows the code, not the method picker, once one has been issued', async () => {
  mock.onGet('/influencers/7/claim').reply(200, { data: claim() });

  render(<ClaimInfluencerScreen />, { wrapper: Providers });

  expect(await screen.findByTestId('claim-code')).toBeTruthy();
  expect(screen.getByTestId('claim-code-value')).toHaveTextContent('reelmap-verify-ABCD2345');
  expect(screen.queryByTestId('claim-methods')).toBeNull();
});

it('re-reads a pending code from the server rather than from state', async () => {
  // The user leaves to edit their bio and comes back — a code kept only in
  // component state would be gone, and it is one-time.
  mock.onGet('/influencers/7/claim').reply(200, { data: claim({ token: 'reelmap-verify-PERSIST1' }) });

  render(<ClaimInfluencerScreen />, { wrapper: Providers });

  expect(await screen.findByTestId('claim-code-value')).toHaveTextContent('reelmap-verify-PERSIST1');
});

it('requests a bio code and swaps to the code view', async () => {
  mock.onGet('/influencers/7/claim').reply(404);
  mock.onPost('/influencers/7/claim').reply(200, { data: claim({ token: 'reelmap-verify-NEW00001' }) });

  render(<ClaimInfluencerScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByText('Verify with a code'));

  expect(await screen.findByTestId('claim-code-value')).toHaveTextContent('reelmap-verify-NEW00001');
  expect(JSON.parse(mock.history.post[0].data)).toEqual({ method: 'bio_code' });
});

it('completes instantly on the linked-account door', async () => {
  mock.onGet('/influencers/7/claim').reply(404);
  mock.onPost('/influencers/7/claim').reply(200, { data: claim({ status: 'verified', method: 'oauth', token: null }) });

  render(<ClaimInfluencerScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByText('I’ve linked this account'));

  expect(await screen.findByTestId('claim-verified')).toBeTruthy();
  expect(JSON.parse(mock.history.post[0].data)).toEqual({ method: 'oauth' });
});

it('sends action=verify when re-checking a pending code', async () => {
  mock.onGet('/influencers/7/claim').reply(200, { data: claim() });
  mock.onPost('/influencers/7/claim').reply(200, { data: claim({ status: 'verified', token: null }) });

  render(<ClaimInfluencerScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByText('I’ve added it — verify'));

  await waitFor(() => expect(mock.history.post).toHaveLength(1));
  // Without `action`, the API would ISSUE A NEW CODE instead of checking the
  // one the user just pasted into their bio.
  expect(JSON.parse(mock.history.post[0].data)).toEqual({ method: 'bio_code', action: 'verify' });
});

it('shows a verified claim as terminal, with a way back', async () => {
  mock.onGet('/influencers/7/claim').reply(200, { data: claim({ status: 'verified', token: null }) });

  render(<ClaimInfluencerScreen />, { wrapper: Providers });

  expect(await screen.findByTestId('claim-verified')).toBeTruthy();
  expect(screen.queryByTestId('claim-methods')).toBeNull();
  expect(screen.queryByTestId('claim-code')).toBeNull();

  fireEvent.press(screen.getByText('Back to profile'));
  expect(mockRouter.back).toHaveBeenCalled();
});

it('surfaces a rejected verification instead of failing silently', async () => {
  mock.onGet('/influencers/7/claim').reply(200, { data: claim() });
  mock.onPost('/influencers/7/claim').reply(422, {
    error: { code: 'validation_failed', details: { code: ['We could not find the code in that bio.'] } },
  });

  render(<ClaimInfluencerScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByText('I’ve added it — verify'));

  // A 422 is a ValidationError, so `generalError` is null and the field errors
  // carry the message — the screen must still leave the user on the code view
  // with the code intact rather than dropping them somewhere.
  await waitFor(() => expect(mock.history.post).toHaveLength(1));
  expect(screen.getByTestId('claim-code-value')).toHaveTextContent('reelmap-verify-ABCD2345');
});

it('tells the user when the request never reached the server', async () => {
  mock.onGet('/influencers/7/claim').reply(404);
  mock.onPost('/influencers/7/claim').networkError();

  render(<ClaimInfluencerScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByText('Verify with a code'));

  expect(await screen.findByText('No connection. Check your network and try again.')).toBeTruthy();
});
