import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import InfluencerProfileScreen from '../[id]/index';
import { api } from '@/api/client';
import type { InfluencerProfile } from '@/api/influencers';
import { useSessionStore } from '@/stores/session';

import { mockRouter } from '../../../jest.setup';

/**
 * The creator-side profile (T-039). An influencer is an identity on a platform
 * that may correspond to no Reelmap account at all — which is why the claim CTA
 * lives here and why its visibility rules are the thing worth pinning.
 */

let mock: AxiosMockAdapter;
let qc: QueryClient;

function influencer(over: Partial<InfluencerProfile> = {}): InfluencerProfile {
  return {
    id: '7',
    platform: 'instagram',
    handle: 'reviewer',
    display_name: 'The Reviewer',
    avatar_url: null,
    claimed: false,
    claimed_by: null,
    follower_count: 1200,
    counters: { promoted_places: 9 },
    ...over,
  };
}

function respond(
  profile: InfluencerProfile,
  viewer: { following: boolean; follow_id: string | null } = { following: false, follow_id: null },
) {
  mock.onGet('/influencers/7').reply(200, { data: profile, meta: { viewer } });
}

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.params = { id: '7' };
  mockRouter.push.mockClear();
  useSessionStore.setState({ user: null, status: 'authed' });
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

it('shows the identity, its platform and its counters', async () => {
  respond(influencer());

  render(<InfluencerProfileScreen />, { wrapper: Providers });

  expect(await screen.findByText('The Reviewer')).toBeTruthy();
  // Twice on purpose — the header and the body both carry the handle, matching
  // the user-profile screen.
  expect(screen.getAllByText('@reviewer')).toHaveLength(2);
  expect(screen.getByText('instagram')).toBeTruthy();
  expect(screen.getByText('9')).toBeTruthy();
  expect(screen.getByText('1200')).toBeTruthy();
});

it('surfaces a missing identity rather than an empty shell', async () => {
  mock.onGet('/influencers/7').reply(404);

  render(<InfluencerProfileScreen />, { wrapper: Providers });

  expect(await screen.findByTestId('influencer-not-found')).toBeTruthy();
});

describe('the claim CTA', () => {
  it('is offered to a signed-in viewer on an unclaimed identity', async () => {
    respond(influencer({ claimed: false }));

    render(<InfluencerProfileScreen />, { wrapper: Providers });

    expect(await screen.findByText('Claim this profile')).toBeTruthy();
  });

  it('disappears once the identity is claimed, and says who owns it', async () => {
    respond(influencer({ claimed: true, claimed_by: 'ada' }));

    render(<InfluencerProfileScreen />, { wrapper: Providers });

    expect(await screen.findByText('Claimed by @ada')).toBeTruthy();
    expect(screen.queryByText('Claim this profile')).toBeNull();
  });

  it('credits an anonymous claim without inventing a username', async () => {
    // The API withholds the claimer when their account is private.
    respond(influencer({ claimed: true, claimed_by: null }));

    render(<InfluencerProfileScreen />, { wrapper: Providers });

    expect(await screen.findByText('Claimed')).toBeTruthy();
  });

  it('is not offered to a guest, who has no account to attach', async () => {
    useSessionStore.setState({ user: null, status: 'guest' });
    respond(influencer({ claimed: false }));

    render(<InfluencerProfileScreen />, { wrapper: Providers });

    await screen.findByText('The Reviewer');
    expect(screen.queryByText('Claim this profile')).toBeNull();
  });

  it('routes to the claim flow', async () => {
    respond(influencer({ claimed: false }));

    render(<InfluencerProfileScreen />, { wrapper: Providers });
    fireEvent.press(await screen.findByText('Claim this profile'));

    expect(mockRouter.push).toHaveBeenCalledWith({
      pathname: '/influencer/[id]/claim',
      params: { id: '7' },
    });
  });
});

describe('following', () => {
  it('follows with the influencer type, not the user type', async () => {
    respond(influencer());
    mock.onPost('/follows').reply(201, { data: { id: '55' } });

    render(<InfluencerProfileScreen />, { wrapper: Providers });
    fireEvent.press(await screen.findByText('Follow'));

    // Getting `followable_type` wrong would silently follow a USER with the
    // same numeric id — a different account entirely.
    await waitFor(() => expect(mock.history.post).toHaveLength(1));
    expect(JSON.parse(mock.history.post[0].data)).toEqual({
      followable_type: 'influencer',
      followable_id: 7,
    });
  });

  it('unfollows through the follow edge id', async () => {
    respond(influencer(), { following: true, follow_id: '55' });
    mock.onDelete('/follows/55').reply(200, { data: null });

    render(<InfluencerProfileScreen />, { wrapper: Providers });
    fireEvent.press(await screen.findByText('Following'));

    await waitFor(() => expect(mock.history.delete).toHaveLength(1));
    expect(mock.history.delete[0].url).toBe('/follows/55');
  });

  it('hides the follow button from a guest', async () => {
    useSessionStore.setState({ user: null, status: 'guest' });
    respond(influencer());

    render(<InfluencerProfileScreen />, { wrapper: Providers });

    await screen.findByText('The Reviewer');
    expect(screen.queryByText('Follow')).toBeNull();
  });
});

it('routes to their map', async () => {
  respond(influencer());

  render(<InfluencerProfileScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByText('View their map'));

  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/influencer/[id]/map', params: { id: '7' } });
});
