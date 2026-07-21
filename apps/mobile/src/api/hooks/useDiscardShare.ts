import { useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';

/**
 * Discard a share the user doesn't want to keep (T-026). `DELETE /shares/:id`
 * transitions it to `rejected` (409 only if already published — the review form
 * never offers Discard on a published share). Drops it from the recent-shares
 * list; navigation back to the composer is the caller's job.
 */
export function useDiscardShare(shareId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: () => api.delete(`/shares/${encodeURIComponent(shareId)}`),
    onSuccess: () => {
      qc.removeQueries({ queryKey: queryKeys.share(shareId) });
      // Drop the discarded share from the recent-shares list (all page sizes).
      qc.invalidateQueries({ queryKey: queryKeys.sharesListAll() });
    },
  });
}
