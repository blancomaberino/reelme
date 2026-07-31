import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import type { AnalysisModel } from '../influencers';
import { queryKeys } from '../keys';
import type { Me } from '../types';
import { useSessionStore } from '@/stores/session';

/**
 * The selectable analysis-model catalog (T-020): `auto` first, then whichever
 * local Ollama vision models are live, then the curated OpenRouter list.
 *
 * Availability is probed server-side per request, so this is deliberately
 * short-lived — a model that went down while the user sat on Settings should
 * stop being offered.
 */
export function useAnalysisModels(opts?: { enabled?: boolean }) {
  return useQuery({
    queryKey: queryKeys.analysisModels(),
    queryFn: async (): Promise<AnalysisModel[]> => {
      const { data } = await api.get<{ data: { models: AnalysisModel[] } }>('/analysis/models');
      return data.data.models;
    },
    staleTime: 60_000,
    // Authed-only: the catalog is fine to fetch for a guest, but the picker it
    // feeds is not shown, so skip the request entirely.
    enabled: opts?.enabled ?? true,
  });
}

/**
 * Set the preferred model. The API validates the id against the same live
 * catalog, so an id that stopped being selectable between render and tap comes
 * back 422 rather than being stored and silently ignored at analysis time.
 */
export function useSetAnalysisModel() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (model: string): Promise<Me> => {
      const { data } = await api.put<{ data: { user: Me } }>('/me/analysis-preference', { model });
      return data.data.user;
    },
    onSuccess: (user) => {
      // The picker's checkmark reads from the session user, so update both it
      // and the ['me'] cache the offline restore rehydrates from (T-103).
      useSessionStore.getState().setUser(user);
      qc.setQueryData(queryKeys.me, user);
    },
  });
}
