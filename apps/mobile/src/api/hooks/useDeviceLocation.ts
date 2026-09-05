import { useQuery } from '@tanstack/react-query';

import { locateUser } from '@/lib/initial-region';

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
    staleTime: 5 * 60_000,
    retry: false,
  });

  return {
    fix,
    at: fix.data?.ok ? fix.data.region : null,
    blocked: fix.data && !fix.data.ok ? fix.data.reason : null,
  };
}
