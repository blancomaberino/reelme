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

it('asks for their places by the endpoint the API actually has', async () => {
  respond(influencer());
  mock.onGet('/influencers/7/places').reply(200, {
    data: [
      { id: '1', name: 'Kraken', slug: 'kraken', lat: -34.9, lng: -56.1, city: 'Piriápolis', country_code: 'UY', status: 'active', thumbnail_url: null, category: null, price_range: null },
    ],
  });

  render(<InfluencerProfileScreen />, { wrapper: Providers });

  // THE regression. The old hook called the VIEWPORT endpoint with
  // `minLng/minLat/maxLng/maxLat` as separate params and a whole-globe extent.
  // The API takes `bbox` as one comma-joined string and rejects a globe-spanning
  // span outright, so every call 422'd — and the screen, unable to tell a failed
  // request from an empty one, told every visitor the creator had no places.
  expect(await screen.findByText('Kraken')).toBeOnTheScreen();

  // The COMPLETE parameter contract, not a couple of spot checks: asserting
  // only `minLng` and `bbox` would still pass a regression that reintroduced
  // `minLat`/`maxLng`/`maxLat`.
  const call = mock.history.get.find((c) => c.url?.includes('/places'));
  expect(call?.url).toBe('/influencers/7/places');
  expect(call?.params).toEqual({ limit: 50 });
});

it('shows a failure as a failure, not as "no places"', async () => {
  respond(influencer());
  mock.onGet('/influencers/7/places').reply(500);

  render(<InfluencerProfileScreen />, { wrapper: Providers });

  // The counter above stays visible, so hiding the section on error makes the
  // screen contradict itself — which is the exact bug this change set out to
  // fix on the map screen, and which I reproduced here in the same commit.
  expect(await screen.findByTestId('influencer-places-error')).toBeOnTheScreen();
});

it('follows the cursor so the list can match the counter past one page', async () => {
  respond(influencer({ counters: { promoted_places: 51 } }));

  const page = (n: number, next: string | null) => ({
    data: [
      { id: String(n), name: `Place ${n}`, slug: `p-${n}`, lat: -34.9, lng: -56.1, city: null, country_code: 'UY', status: 'active', thumbnail_url: null, category: null, price_range: null },
    ],
    meta: { pagination: { next_cursor: next, prev_cursor: null, limit: 50 } },
  });

  mock.onGet('/influencers/7/places').replyOnce(200, page(1, 'CURSOR2'));
  mock.onGet('/influencers/7/places').replyOnce(200, page(2, null));

  render(<InfluencerProfileScreen />, { wrapper: Providers });

  // A single page reintroduces the counter-vs-list disagreement at 50 rather
  // than removing it: "51 Lugares" over a list of 50.
  expect(await screen.findByText('Place 1')).toBeOnTheScreen();
  expect(await screen.findByText('Place 2')).toBeOnTheScreen();

  const second = mock.history.get.filter((c) => c.url?.includes('/places'))[1];
  expect(second?.params).toEqual({ limit: 50, cursor: 'CURSOR2' });
});

it('routes to their map', async () => {
  respond(influencer());

  render(<InfluencerProfileScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByText('View their map'));

  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/influencer/[id]/map', params: { id: '7' } });
});
