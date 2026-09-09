import { useQuery } from '@tanstack/react-query';

import { locateUser } from '@/lib/initial-region';
import { VIEWER_FIX_MAX_AGE_MS } from '@/lib/location';

import { queryKeys } from '../keys';

/**
 * The device's position as a QUERY, not an effect writing state.
 *
 * `locateUser` never throws — it answers with a reason — so a refusal is data,
 * and "try again" is `refetch()` rather than a second copy of the same logic.
 *
 * The shared key is load-bearing, not incidental: every screen that needs a fix
 * reads the same cache entry, so moving between the map, the offers browse and
 * Tonight costs no second permission prompt and no second GPS acquisition. The
 * `staleTime` is part of that contract, which is exactly why it lives here
 * rather than as a number two screens have to keep equal.
 */
export function useDeviceLocation() {
  const fix = useQuery({
    queryKey: queryKeys.deviceLocation(),
    queryFn: locateUser,
    // The SAME bound `locateUser` refuses a stale fix with, not a number of its
    // own. It was five minutes against a two-minute bound, which reopened at the
    // cache exactly what the bound closes at acquisition: remount Tonight three
    // minutes after the fix and a three-minute-old position is re-served without
    // a refetch, and the walk across town is reported as "50 m" after all. One
    // rule, every reader of the state it governs — `use-viewer-position` already
    // keys off this constant, and this hook was the writer that did not.
    staleTime: VIEWER_FIX_MAX_AGE_MS,
    retry: false,
  });

  return {
    fix,
    at: fix.data?.ok ? fix.data.region : null,
    blocked: fix.data && !fix.data.ok ? fix.data.reason : null,
  };
}
