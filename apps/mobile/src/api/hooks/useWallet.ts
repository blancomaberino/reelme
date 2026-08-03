import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import type { Payout, Wallet, WalletEntry } from '../wallet';

/**
 * The wallet (T-046).
 *
 * `staleTime: 0` everywhere, deliberately (05 §state rules): this is money. A
 * balance served from cache after a payout just moved it is the one number a
 * user will notice being wrong, and they will not assume it is stale — they
 * will assume it is gone.
 */
export function useWallet() {
  return useQuery({
    queryKey: queryKeys.wallet(),
    queryFn: async (): Promise<Wallet> => {
      const { data } = await api.get<{ data: Wallet }>('/wallet');
      return data.data;
    },
    staleTime: 0,
  });
}

/** The statement, paged. */
export function useWalletLedger() {
  return useInfiniteQuery({
    queryKey: queryKeys.walletLedger(),
    initialPageParam: null as string | null,
    queryFn: async ({ pageParam }) => {
      const { data } = await api.get<{ data: WalletEntry[]; meta: { pagination: { next_cursor: string | null } } }>(
        '/wallet/ledger',
        { params: { limit: 25, cursor: pageParam ?? undefined } },
      );
      return data;
    },
    getNextPageParam: (last) => last.meta.pagination.next_cursor,
    staleTime: 0,
  });
}

export function usePayouts() {
  return useQuery({
    queryKey: queryKeys.walletPayouts(),
    queryFn: async (): Promise<Payout[]> => {
      const { data } = await api.get<{ data: Payout[] }>('/wallet/payouts');
      return data.data;
    },
    staleTime: 0,
  });
}

/**
 * Cash out everything available.
 *
 * Carries a client-generated `Idempotency-Key` (03 §1). A phone on a bad
 * connection retries a request whose answer it never saw — and without the key
 * that retry is either a second payout or a baffling "insufficient balance" for
 * money still visible on screen. The key is minted per ATTEMPT, not per render,
 * so a genuine second cash-out later is a genuine second payout.
 */
export function useRequestPayout() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<Payout> => {
      const { data } = await api.post<{ data: Payout }>('/wallet/payouts', null, {
        headers: { 'Idempotency-Key': newIdempotencyKey() },
      });
      return data.data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: queryKeys.wallet() });
      void qc.invalidateQueries({ queryKey: queryKeys.walletLedger() });
      void qc.invalidateQueries({ queryKey: queryKeys.walletPayouts() });
    },
  });
}

/** A fresh Stripe onboarding URL. Never cached — links are single-use. */
export function useOnboardingLink() {
  return useMutation({
    mutationFn: async (): Promise<string> => {
      const { data } = await api.post<{ data: { url: string } }>('/wallet/connect/onboarding-link');
      return data.data.url;
    },
  });
}

/**
 * Random enough that two attempts never collide, without pulling in a uuid
 * dependency for a string the server only compares for equality.
 */
function newIdempotencyKey(): string {
  return `payout-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}
