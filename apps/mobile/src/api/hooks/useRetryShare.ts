import { useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';

/**
 * Re-run the ingest pipeline for a failed share (POST /shares/{id}/retry). The
 * API only allows it from a retryable state (Failed, or Review after a
 * `fetch_unavailable`) and 409s otherwise. On success the share transitions
 * back to `pending`, so we invalidate its query to resume status polling.
 */
export function useRetryShare(shareId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => api.post(`/shares/${encodeURIComponent(shareId)}/retry`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: queryKeys.share(shareId) });
    },
  });
}
