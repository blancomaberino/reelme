import { useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { ShareDetail, ShareUpdatePayload } from '../shares';

/**
 * Correct + (optionally) publish a share stuck in `review` (T-026).
 * `PATCH /shares/:id` deep-merges the partial `extraction` onto the original run,
 * attaches a picked dedupe candidate / manual pin, and — with `action: 'publish'`
 * — resumes the resolve→publish pipeline. The response is the fresh share, so we
 * seed the cache and invalidate the map / my-places surfaces the new pin lands on.
 */
export function useUpdateShare(shareId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (payload: ShareUpdatePayload): Promise<ShareDetail> => {
      const { data } = await api.patch<{ data: ShareDetail }>(
        `/shares/${encodeURIComponent(shareId)}`,
        payload,
      );
      return data.data;
    },
    onSuccess: (share) => {
      qc.setQueryData(queryKeys.share(shareId), share);
      qc.invalidateQueries({ queryKey: queryKeys.share(shareId) });
      qc.invalidateQueries({ queryKey: queryKeys.mapAll() });
      qc.invalidateQueries({ queryKey: queryKeys.myPlacesAll() });
    },
  });
}
