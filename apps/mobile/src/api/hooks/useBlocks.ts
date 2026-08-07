import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';

/**
 * Blocking another account (T-054, IR-6 / Apple Guideline 1.2).
 *
 * A UGC app has to let a person stop someone else's content reaching them
 * without waiting on a moderator — reporting is a request, blocking is a
 * decision, and the store review looks for both.
 */
export type BlockedUser = {
  id: string;
  username: string;
  name: string | null;
  avatar_url: string | null;
};

/** Accounts this user has blocked. The only place a block can be found again. */
export function useBlocks() {
  return useQuery({
    queryKey: queryKeys.blocks(),
    queryFn: async (): Promise<BlockedUser[]> => {
      const { data } = await api.get<{ data: BlockedUser[] }>('/me/blocks');
      return data.data;
    },
  });
}

export function useBlockUser() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (username: string): Promise<void> => {
      await api.post(`/me/blocks/${encodeURIComponent(username)}`);
    },
    onSuccess: (_result, username) => {
      /*
       * Blocking changes what is visible almost everywhere, so the cache has to
       * be told — a stale feed that still shows the blocked account's shares is
       * the exact failure this feature exists to prevent, and it would persist
       * until each query happened to go stale on its own.
       *
       * The blocked profile is dropped rather than invalidated: it is a 404 for
       * this viewer now, and refetching it would only turn a cached screen into
       * an error screen.
       */
      qc.removeQueries({ queryKey: queryKeys.profile(username) });
      void qc.invalidateQueries({ queryKey: queryKeys.blocks() });
      void qc.invalidateQueries({ queryKey: ['feed'] });
    },
  });
}

export function useUnblockUser() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (username: string): Promise<void> => {
      await api.delete(`/me/blocks/${encodeURIComponent(username)}`);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: queryKeys.blocks() });
      void qc.invalidateQueries({ queryKey: ['feed'] });
    },
  });
}
