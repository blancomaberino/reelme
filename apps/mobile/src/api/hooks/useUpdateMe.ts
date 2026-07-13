import { useMutation, useQueryClient } from '@tanstack/react-query';

import { useSessionStore } from '@/stores/session';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { Me } from '../types';

/** Fields the user can edit on their own profile (PATCH /me). */
export type UpdateMeInput = {
  name?: string;
  bio?: string | null;
  birthdate?: string | null;
  favorite_topics?: string[];
  favorite_foods?: string[];
};

/**
 * Save profile edits (PATCH /me). On success, mirrors the fresh user into both
 * the ['me'] query cache and the session store so every screen updates.
 */
export function useUpdateMe() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (input: UpdateMeInput): Promise<Me> => {
      const { data } = await api.patch<{ data: { user: Me } }>('/me', input);
      return data.data.user;
    },
    onSuccess: (user) => {
      qc.setQueryData(queryKeys.me, user);
      useSessionStore.getState().setUser(user);
    },
  });
}
