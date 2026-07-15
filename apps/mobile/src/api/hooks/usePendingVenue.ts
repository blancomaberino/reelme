import { useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';

/**
 * Resolve or dismiss a still-pending venue on a partially-published multi-place
 * share (T-071). Resolve attaches + publishes a picked candidate; dismiss drops
 * the venue. Both refetch the share so the pending list + published pins update.
 */
export function usePendingVenue(shareId: string) {
  const qc = useQueryClient();
  const invalidate = () => {
    qc.invalidateQueries({ queryKey: queryKeys.share(shareId) });
    qc.invalidateQueries({ queryKey: queryKeys.myPlacesAll() });
    qc.invalidateQueries({ queryKey: queryKeys.mapAll() });
  };

  const resolve = useMutation({
    mutationFn: (v: { index: number; placeId: string }) =>
      api.post(`/shares/${encodeURIComponent(shareId)}/pending/${v.index}/resolve`, { place_id: Number(v.placeId) }),
    onSuccess: invalidate,
  });

  const dismiss = useMutation({
    mutationFn: (index: number) => api.delete(`/shares/${encodeURIComponent(shareId)}/pending/${index}`),
    onSuccess: invalidate,
  });

  return { resolve, dismiss };
}
