import { useQuery } from '@tanstack/react-query';
import type { InfluencerDashboard } from '@reelmap/contracts';

import { api } from '../client';
import { queryKeys } from '../keys';

/** The windows the API serves (03 §2.14). */
export type DashboardPeriod = InfluencerDashboard['period'];

export const DASHBOARD_PERIODS: DashboardPeriod[] = ['30d', '90d', 'all'];

/**
 * The influencer earnings funnel (T-048, 06 §5.2).
 *
 * `staleTime: 0`, like the wallet next to it: the figures include a live
 * balance, and money served from cache after a payout just moved it is the one
 * number a user notices being wrong — they will not read it as stale, they will
 * read it as gone.
 *
 * 403 for an account with no claimed influencer identity. That is not an error
 * state worth retrying, so the screen treats it as "this isn't for you" rather
 * than as a failure (and `retry: false` stops us asking three times).
 */
export function useInfluencerDashboard(period: DashboardPeriod, opts?: { enabled?: boolean }) {
  return useQuery({
    queryKey: queryKeys.influencerDashboard(period),
    queryFn: async (): Promise<InfluencerDashboard> => {
      const { data } = await api.get<{ data: InfluencerDashboard }>('/me/influencer/dashboard', {
        params: { period },
      });
      return data.data;
    },
    staleTime: 0,
    retry: false,
    enabled: opts?.enabled ?? true,
  });
}
