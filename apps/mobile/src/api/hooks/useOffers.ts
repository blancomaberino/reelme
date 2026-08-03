import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { CreateOfferInput, Offer, UpdateOfferInput, Venue } from '../offers';

/**
 * Restaurant offer management (T-042).
 *
 * Every mutation invalidates BOTH the operator's list and the single offer, and
 * also the map — an offer going live or being paused changes the `has_active_offer`
 * badge on the venue's pin, and a stale badge promises a diner a promotion that
 * is no longer running.
 */
function useInvalidateOffers() {
  const qc = useQueryClient();

  return (id?: string) => {
    void qc.invalidateQueries({ queryKey: queryKeys.myOffers() });
    void qc.invalidateQueries({ queryKey: queryKeys.mapAll() });
    if (id) void qc.invalidateQueries({ queryKey: queryKeys.offer(id) });
  };
}

/**
 * The venues the signed-in user operates. Empty for everyone who has not had a
 * place claim verified — which is what gates the whole restaurant surface.
 */
export function useVenues() {
  return useQuery({
    queryKey: queryKeys.venues(),
    queryFn: async (): Promise<Venue[]> => {
      const { data } = await api.get<{ data: Venue[] }>('/me/venues');
      return data.data;
    },
    staleTime: 60_000,
  });
}

/**
 * Every offer for every venue the caller operates, in every state — `?mine=1`.
 * Drafts and paused offers are deliberately included: this is the management
 * view, not the diner browse.
 *
 * Bounded at the API's maximum page (100) and deliberately NOT paginated: an
 * offer is created one at a time by a person, and 100 live+draft+paused offers
 * across one operator's venues is far past what the grouped list is readable
 * at. If that ever stops being true the fix is an infinite query here, not a
 * larger limit — the API already refuses anything above 100.
 */
export function useMyOffers() {
  return useQuery({
    queryKey: queryKeys.myOffers(),
    queryFn: async (): Promise<Offer[]> => {
      const { data } = await api.get<{ data: Offer[] }>('/offers', { params: { mine: 1, limit: 100 } });
      return data.data;
    },
  });
}

/** One offer, for the edit form. */
export function useOffer(id: string | null) {
  return useQuery({
    queryKey: queryKeys.offer(id ?? ''),
    queryFn: async (): Promise<Offer> => {
      const { data } = await api.get<{ data: Offer }>(`/offers/${encodeURIComponent(id as string)}`);
      return data.data;
    },
    enabled: !!id,
  });
}

export function useCreateOffer() {
  const invalidate = useInvalidateOffers();

  return useMutation({
    mutationFn: async (input: CreateOfferInput): Promise<Offer> => {
      const { data } = await api.post<{ data: Offer }>('/offers', input);
      return data.data;
    },
    onSuccess: (offer) => invalidate(offer.id),
  });
}

/** Edit, pause (`status: 'paused'`), or resume (`status: 'active'`). */
export function useUpdateOffer() {
  const invalidate = useInvalidateOffers();

  return useMutation({
    mutationFn: async ({ id, ...body }: UpdateOfferInput): Promise<Offer> => {
      const { data } = await api.patch<{ data: Offer }>(`/offers/${encodeURIComponent(id)}`, body);
      return data.data;
    },
    onSuccess: (offer) => invalidate(offer.id),
  });
}

/**
 * Archive. The API never hard-deletes — redemptions and ledger entries point at
 * the offer — so this returns the archived row rather than nothing.
 */
export function useArchiveOffer() {
  const invalidate = useInvalidateOffers();

  return useMutation({
    mutationFn: async (id: string): Promise<Offer> => {
      const { data } = await api.delete<{ data: Offer }>(`/offers/${encodeURIComponent(id)}`);
      return data.data;
    },
    onSuccess: (offer) => invalidate(offer.id),
  });
}
