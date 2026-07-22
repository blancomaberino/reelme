import { useMutation, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { ShareDetail } from '../shares';

/**
 * Skip the confirm step and publish an uncertain share's best guess (T-098):
 * `POST /shares/:id/publish-best-guess`. The place goes live immediately and is
 * flagged for an admin to check — the sharer's "just add it, I don't want to
 * revise" path. 409 when the review isn't best-guessable (a geocode failure needs
 * a location first). Seeds + invalidates the share so the status screen picks up
 * the resumed pipeline; refreshes the map / my-places the new pin lands on.
 */
export function usePublishBestGuess(shareId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<ShareDetail> => {
      const { data } = await api.post<{ data: ShareDetail }>(
        `/shares/${encodeURIComponent(shareId)}/publish-best-guess`,
      );
      return data.data;
    },
    onSuccess: (share) => {
      qc.setQueryData(queryKeys.share(shareId), share);
      qc.invalidateQueries({ queryKey: queryKeys.share(shareId) });
      qc.invalidateQueries({ queryKey: queryKeys.sharesListAll() });
      qc.invalidateQueries({ queryKey: queryKeys.mapAll() });
      qc.invalidateQueries({ queryKey: queryKeys.myPlacesAll() });
    },
  });
}
