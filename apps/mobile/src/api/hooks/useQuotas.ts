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

/**
 * Never poll `/me` faster than this, whatever the arithmetic says. See the
 * `refetchInterval` note below — this is the difference between one scheduled
 * refetch and a millisecond hot loop.
 */
const MIN_QUOTA_REFETCH_MS = 30_000;

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
     * Refetch AT the reset boundary.
     *
     * `staleTime` does not schedule anything; it only marks data refetchable.
     * A screen left open in the foreground across midnight UTC would therefore
     * hold yesterday's `remaining: 0` indefinitely and keep refusing to share,
     * while displaying a reset time that had already passed — a message
     * visibly contradicting itself, and no way out but backgrounding the app.
     *
     * So the interval is the time until that boundary, plus a second so the
     * server has certainly rolled over. In the common case — the screen open
     * for a minute, well before reset — that timer never fires.
     *
     * THE FLOOR IS LOAD-BEARING. `refetchInterval` is a REPEATING poll, not a
     * one-shot, so a `resets_at` already in the past cannot be answered with
     * "refetch immediately": returning 1 polls `/me` a thousand times a second,
     * forever, for as long as the boundary stays behind us. That is reachable
     * without any bug on our side — a device clock ahead of the server's, or a
     * snapshot restored from disk after the window closed. The floor turns the
     * worst case into a 30s poll that heals itself on the first fresh payload.
     *
     * Found because a test fixture's fixed `resets_at` fell into the past as
     * real time moved, and the hot loop's successful refetches cleared the
     * global rate-limit banner mid-assertion.
     */
    refetchInterval: (query) => {
      const resetsAt = query.state.data?.resets_at;
      if (resetsAt === undefined) return false;

      const msUntilReset = Date.parse(resetsAt) + 1_000 - Date.now();

      // NaN (an unparseable timestamp) fails every comparison, so it lands on
      // the floor rather than becoming an interval of NaN.
      return msUntilReset > MIN_QUOTA_REFETCH_MS ? msUntilReset : MIN_QUOTA_REFETCH_MS;
    },
  });
}
