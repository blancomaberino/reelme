import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import InfluencerDashboardScreen from '../dashboard';
import WalletScreen from '../../(main)/wallet';
import { api } from '@/api/client';
import { openWebUrl } from '@/lib/linking';
import { mockRouter } from '../../../jest.setup';

/**
 * The influencer earnings dashboard (T-048, 06 §5.2).
 *
 * The API for this shipped before any UI, so the risk here is not "does it
 * fetch" — it is the three states a chart screen gets wrong: nothing earned yet,
 * an account with no claimed identity (403, which is NOT an error), and a post
 * whose share was deleted but whose earnings must still be counted.
 */
jest.mock('@/lib/linking', () => ({ openWebUrl: jest.fn(), isHttpUrl: () => true }));

let qc: QueryClient;
let mock: AxiosMockAdapter;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

function dashboard(overrides: Record<string, unknown> = {}) {
  return {
    data: {
      period: '30d',
      influencer: { id: '7', handle: 'chef', platform: 'instagram' },
      funnel: {
        shares: 12,
        views: null,
        views_tracked_since: null,
        issued: 8,
        redeemed: 3,
        earnings: { amount: 90, currency: 'EUR' },
      },
      by_place: [
        { place: { id: '1', slug: 'bar-tinto', name: 'Bar Tinto' }, issued: 5, redeemed: 2, earnings: { amount: 60, currency: 'EUR' } },
        { place: { id: '2', slug: 'la-fonda', name: 'La Fonda' }, issued: 3, redeemed: 1, earnings: { amount: 30, currency: 'EUR' } },
      ],
      top_places: [
        { place: { id: '1', slug: 'bar-tinto', name: 'Bar Tinto' }, issued: 5, redeemed: 2, earnings: { amount: 60, currency: 'EUR' } },
      ],
      by_source: [
        {
          share_id: '31',
          post: { url: 'https://www.instagram.com/reel/abc/', platform: 'instagram' },
          issued: 5,
          redeemed: 2,
          earnings: { amount: 60, currency: 'EUR' },
        },
      ],
      money: { available: { amount: 90, currency: 'EUR' }, threshold: { amount: 2500, currency: 'EUR' } },
      connect: { onboarded: true, payouts_enabled: true },
      ...overrides,
    },
    meta: {},
  };
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.push.mockClear();
  (openWebUrl as jest.Mock).mockClear();
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

it('is reachable from the wallet tab', async () => {
  mock.onGet('/wallet').reply(200, {
    data: {
      balance: { available: { amount: 90, currency: 'EUR' }, pending: { amount: 0, currency: 'EUR' } },
      lifetime_earnings: { amount: 90, currency: 'EUR' },
      fees_owed: null,
      minimum_payout: { amount: 2500, currency: 'EUR' },
      can_request_payout: false,
      connect: { onboarded: true, payouts_enabled: true, requirements_due: [] },
      recent_entries: [],
    },
    meta: {},
  });
  mock.onGet('/wallet/ledger').reply(200, { data: [], meta: { pagination: { next_cursor: null } } });

  render(<WalletScreen />, { wrapper: Providers });

  // A screen nobody can reach is not done — this presses the real control.
  fireEvent.press(await screen.findByTestId('wallet-earnings-entry'));
  expect(mockRouter.push).toHaveBeenCalledWith('/influencer/dashboard');
});

it('shows the funnel and what it earned', async () => {
  mock.onGet('/me/influencer/dashboard').reply(200, dashboard());

  render(<InfluencerDashboardScreen />, { wrapper: Providers });

  await screen.findByTestId('earnings-funnel');
  // Reach is CONTEXT, not a funnel stage: posts and codes are different units,
  // and charting 12 posts above 8 codes drew a bar that shrank for no reason.
  expect(screen.getByTestId('earnings-reach')).toHaveTextContent('12 posts on the map');
  expect(screen.getByText('8')).toBeOnTheScreen();
  expect(screen.getByText('3')).toBeOnTheScreen();
  expect(screen.getByTestId('earnings-total')).toHaveTextContent('€0.90');
});

it('speaks in singulars when there is one of something', async () => {
  mock.onGet('/me/influencer/dashboard').reply(200, dashboard({
    funnel: { shares: 1, views: null, views_tracked_since: null, issued: 1, redeemed: 1, earnings: { amount: 30, currency: 'EUR' } },
  }));

  render(<InfluencerDashboardScreen />, { wrapper: Providers });

  // "1 posts on the map" / "1 codes taken" shipped on the first device run —
  // the kind of thing no assertion catches unless it asks for it.
  expect(await screen.findByTestId('earnings-reach')).toHaveTextContent('1 post on the map');
  expect(screen.getByText('code taken')).toBeOnTheScreen();
  expect(screen.getByText('visit paid')).toBeOnTheScreen();
});

it('re-asks the API when the period changes', async () => {
  mock.onGet('/me/influencer/dashboard').reply(200, dashboard());

  render(<InfluencerDashboardScreen />, { wrapper: Providers });
  await screen.findByTestId('earnings-funnel');

  fireEvent.press(screen.getByTestId('earnings-period-all'));

  // The regression that matters on any screen with a filter: the control moves
  // but the data doesn't. Assert the SECOND request, and its period.
  await waitFor(() => expect(mock.history.get).toHaveLength(2));
  expect(mock.history.get[1].params).toEqual({ period: 'all' });
});

it('says views are unmeasured instead of letting the chart imply zero', async () => {
  mock.onGet('/me/influencer/dashboard').reply(200, dashboard());

  render(<InfluencerDashboardScreen />, { wrapper: Providers });

  expect(await screen.findByTestId('earnings-views-untracked')).toBeOnTheScreen();
});

it('opens the original post, and leaves a deleted one inert', async () => {
  mock.onGet('/me/influencer/dashboard').reply(200, dashboard({
    by_source: [
      { share_id: '31', post: { url: 'https://www.instagram.com/reel/abc/', platform: 'instagram' }, issued: 5, redeemed: 2, earnings: { amount: 60, currency: 'EUR' } },
      { share_id: null, post: null, issued: 3, redeemed: 1, earnings: { amount: 30, currency: 'EUR' } },
    ],
  }));

  render(<InfluencerDashboardScreen />, { wrapper: Providers });

  fireEvent.press(await screen.findByTestId('earnings-source-31'));
  expect(openWebUrl).toHaveBeenCalledWith('https://www.instagram.com/reel/abc/');

  // The deleted row still SHOWS — hiding it would make the rows stop summing
  // to the total above — but it has nowhere to go.
  (openWebUrl as jest.Mock).mockClear();
  fireEvent.press(screen.getByTestId('earnings-source-deleted'));
  expect(openWebUrl).not.toHaveBeenCalled();
});

it('draws no bar at all for a stage nobody reached', async () => {
  mock.onGet('/me/influencer/dashboard').reply(200, dashboard({
    funnel: { shares: 4, views: null, views_tracked_since: null, issued: 6, redeemed: 0, earnings: { amount: 0, currency: 'EUR' } },
  }));

  render(<InfluencerDashboardScreen />, { wrapper: Providers });
  await screen.findByTestId('earnings-funnel');

  // The 2% minimum width exists so a tiny-but-real stage stays visible. Applied
  // to zero it painted a sliver — a bar saying "some" beside a number saying
  // "none", which is the most misreadable state a funnel has.
  const bars = screen.getByTestId('earnings-funnel').findAllByProps({ testID: 'earnings-bar' });
  expect(bars[bars.length - 1].props.style).toEqual(
    expect.arrayContaining([expect.objectContaining({ width: '0%' })]),
  );
});

it('offers an empty state rather than a chart of zeros', async () => {
  mock.onGet('/me/influencer/dashboard').reply(200, dashboard({
    funnel: { shares: 2, views: null, views_tracked_since: null, issued: 0, redeemed: 0, earnings: { amount: 0, currency: 'EUR' } },
    by_place: [],
    top_places: [],
    by_source: [],
  }));

  render(<InfluencerDashboardScreen />, { wrapper: Providers });

  expect(await screen.findByTestId('earnings-empty')).toBeOnTheScreen();
  expect(screen.queryByTestId('earnings-funnel')).toBeNull();
});

it('treats a 403 as "not for you", not as an error to retry', async () => {
  mock.onGet('/me/influencer/dashboard').reply(403);

  render(<InfluencerDashboardScreen />, { wrapper: Providers });

  await screen.findByText('For claimed profiles');
  // No "try again" — retrying a 403 never succeeds, and offering it would make
  // an explicable state look like a broken one.
  expect(screen.queryByText('Try again')).toBeNull();
  expect(screen.queryByTestId('earnings-funnel')).toBeNull();
});

it('offers a retry on a real failure', async () => {
  mock.onGet('/me/influencer/dashboard').reply(500);

  render(<InfluencerDashboardScreen />, { wrapper: Providers });

  expect(await screen.findByText('Try again')).toBeOnTheScreen();
});
