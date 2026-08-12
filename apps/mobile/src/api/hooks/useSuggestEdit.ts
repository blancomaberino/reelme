import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { PlaceEditSuggestion, SuggestEditInput } from '../suggestions';

/**
 * Propose a correction to a place's business info (T-083).
 *
 * One endpoint for both framings. A verified operator's patch applies on the
 * spot and comes back `approved`; everyone else's is filed `pending` for
 * moderation. The caller reads `status` to decide what to say afterwards — the
 * client never predicts which it will be, because ownership is re-derived from
 * the claim server-side and a revoked claim has to change the outcome
 * immediately, not at the next app launch.
 */
export function useSuggestEdit(slug: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (input: SuggestEditInput): Promise<PlaceEditSuggestion> => {
      const { data } = await api.post<{ data: PlaceEditSuggestion }>(
        `/places/${encodeURIComponent(slug)}/suggestions`,
        input,
      );
      return data.data;
    },
    onSuccess: (suggestion) => {
      // A queued proposal changed nothing yet — refetching would be pure noise,
      // and on the map it would be a full viewport round-trip per suggestion.
      if (suggestion.status !== 'approved') {
        void qc.invalidateQueries({ queryKey: queryKeys.venueSuggestions() });
        return;
      }

      // An operator edit landed: the detail is stale, and so is every surface
      // that renders the venue's NAME — the pin, the list rows, the saved lists.
      void qc.invalidateQueries({ queryKey: queryKeys.place(slug) });
      void qc.invalidateQueries({ queryKey: queryKeys.mapAll() });
      void qc.invalidateQueries({ queryKey: queryKeys.myPlacesAll() });
      void qc.invalidateQueries({ queryKey: queryKeys.venueSuggestions() });
    },
  });
}

/**
 * Corrections other people have proposed for the venues the caller operates —
 * read-only, and pending ones only.
 *
 * A moderator decides these, not the operator: a venue that could approve its
 * own listing edits could also silently reject every correction to it, which is
 * the failure mode the queue exists to prevent. So this screen answers "what
 * are people telling us about our listing", and nothing more.
 */
export function useVenueSuggestions(enabled = true) {
  return useQuery({
    queryKey: queryKeys.venueSuggestions(),
    queryFn: async (): Promise<PlaceEditSuggestion[]> => {
      const { data } = await api.get<{ data: PlaceEditSuggestion[] }>('/me/venues/suggestions');
      return data.data;
    },
    enabled,
    // Moderation is a human process measured in days; re-asking on every focus
    // would be a request per screen visit for a list that rarely moves.
    staleTime: 60_000,
    gcTime: 5 * 60_000,
  });
}
