import { QueryClient } from '@tanstack/react-query';

import { clearPersistedQueryCache } from '@/lib/query-persist';

/**
 * The app's one QueryClient.
 *
 * It lives here rather than in `app/_layout.tsx` because non-React code needs
 * it too — the axios interceptor has to tear the cache down on a 401, and it
 * cannot import a route file to get at it.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 60_000,
      retry: 2,
      // Default `networkMode: 'online'`: with onlineManager wired to NetInfo
      // (lib/network.ts), a query with no cached data PARKS
      // (`fetchStatus: 'paused'`) while offline instead of burning its retries.
      // Screens read `isPaused` to tell "offline" apart from "failed" and from
      // "genuinely empty" (T-103).
    },
    mutations: {
      retry: 0,
      // Writes deliberately opt OUT of pausing. The default would hold an
      // offline mutation pending and replay it whenever the network returns —
      // possibly minutes later, on a screen the user has long left, against
      // state they've since changed. Failing immediately with a NetworkError
      // lets the screen say so and lets the user decide to retry.
      networkMode: 'always',
    },
  },
});

/**
 * End-of-session teardown: drop the cached data in memory AND on disk.
 *
 * Both halves, in this order, or neither works. Clearing only the disk copy
 * leaves the previous account's places in the live cache, and the persister —
 * which is subscribed to that cache — writes them straight back on the next
 * change. Clearing only memory leaves them readable on disk after sign-out.
 *
 * `useLogout` does the same pair inline against its *context* client (the
 * idiomatic form inside a hook) — it is not a duplicate to be unified away.
 * This entry point exists for callers with no React context, i.e. the axios
 * 401 interceptor.
 */
export async function resetClientCache(): Promise<void> {
  queryClient.clear();
  await clearPersistedQueryCache();
}
