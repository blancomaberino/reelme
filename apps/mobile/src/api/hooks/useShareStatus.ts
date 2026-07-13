import { useQuery } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import { isTerminal, type ShareDetail } from '../shares';

export async function fetchShare(id: string): Promise<ShareDetail> {
  const { data } = await api.get<{ data: ShareDetail }>(`/shares/${encodeURIComponent(id)}`);
  return data.data;
}

/**
 * Poll a share's status until the pipeline reaches a terminal state. `enabled`
 * lets the screen start polling only after a submission. The interval stops
 * itself once `isTerminal(status)` — no runaway polling on a published pin.
 */
export function useShareStatus(id: string | null) {
  return useQuery({
    queryKey: queryKeys.share(id ?? ''),
    queryFn: () => fetchShare(id as string),
    enabled: !!id,
    // Always revalidate from the API — never trust a stale/seeded entry (the
    // POST 202 body is incomplete), so a fresh submission fetches the real state
    // immediately even when it lands already-terminal (idempotent replay).
    staleTime: 0,
    gcTime: 0,
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      return status && isTerminal(status) ? false : 2000;
    },
  });
}
