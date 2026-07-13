import { useQuery } from '@tanstack/react-query';

import { api } from '../client';
import { isTerminal, type ShareDetail } from '../shares';

/**
 * The viewer's own recent shares (GET /shares), newest first. Polls every 3s
 * while any share is still moving through the pipeline, and stops once they're
 * all terminal — so the "Recent shares" list advances live then goes quiet.
 */
export function useShares(limit = 10) {
  return useQuery({
    queryKey: ['shares', 'list', limit] as const,
    queryFn: async () => {
      const { data } = await api.get<{ data: ShareDetail[] }>('/shares', { params: { limit } });
      return data.data;
    },
    staleTime: 0,
    refetchInterval: (query) => {
      const shares = query.state.data;
      const anyInFlight = shares?.some((s) => !isTerminal(s.status)) ?? false;
      return anyInFlight ? 3000 : false;
    },
  });
}
