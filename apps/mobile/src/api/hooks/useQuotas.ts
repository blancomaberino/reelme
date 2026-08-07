import { useQuery } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';

/**
 * The caller's remaining daily allowance (T-051, NFR-12).
 *
 * Read from `GET /me`'s `meta` rather than the user payload: this is a fact
 * about right now, on a clock nothing else in the profile follows. The point of
 * having it at all is that a screen can say "daily limit reached — resets at X"
 * BEFORE the tap. A quota the client cannot see is one it can only discover by
 * hitting it, which turns a designed limit into what looks like a bug.
 */
export type Quotas = {
  shares: { used: number; limit: number; remaining: number };
  ai: { spent_usd: number; budget_usd: number; remaining_usd: number };
  /** ISO-8601, always midnight UTC — see the server-side QuotaSnapshot. */
  resets_at: string;
};

export function useQuotas() {
  return useQuery({
    queryKey: queryKeys.quotas(),
    queryFn: async (): Promise<Quotas> => {
      const { data } = await api.get<{ meta: { quotas: Quotas } }>('/me');
      return data.meta.quotas;
    },
    // Short, not zero: the numbers move as the user shares, and a stale
    // "0 remaining" would block someone whose window has since reset.
    staleTime: 30_000,
    /*
     * One scheduled refetch, at the reset boundary — NOT a poll.
     *
     * `staleTime` does not schedule anything; it only marks data refetchable.
     * A screen left open in the foreground across midnight UTC would therefore
     * hold yesterday's `remaining: 0` indefinitely and keep refusing to share,
     * while displaying a reset time that had already passed — a message
     * visibly contradicting itself, and no way out but backgrounding the app.
     *
     * So the interval is the time UNTIL that boundary (plus a second, so the
     * server has certainly rolled over), and `false` at every other moment.
     * The common case — the screen open for a minute, well before reset —
     * schedules a single timer that never fires.
     */
    refetchInterval: (query) => {
      const resetsAt = query.state.data?.resets_at;
      if (resetsAt === undefined) return false;

      const msUntilReset = Date.parse(resetsAt) + 1_000 - Date.now();

      // Already past it (a snapshot from before the boundary): refetch now.
      return msUntilReset > 0 ? msUntilReset : 1;
    },
  });
}
