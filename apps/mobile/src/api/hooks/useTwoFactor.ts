import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { deviceName } from './useAuth';
import { queryKeys } from '../keys';
import type { RecoveryCodes, TwoFactorSetup, TwoFactorStatus } from '../two-factor';
import { setToken } from '../token';
import type { AuthResponse } from '../types';
import { useSessionStore } from '@/stores/session';

/**
 * Two-factor authentication (T-068).
 *
 * Note what is NOT cached here: the setup payload and the recovery codes are
 * returned by mutations, never by a query, so they are never written into a
 * cache that the offline persister might put on disk. They live in screen state
 * for exactly as long as the screen does.
 */
export function useTwoFactorStatus(opts?: { enabled?: boolean }) {
  return useQuery({
    queryKey: queryKeys.twoFactor(),
    queryFn: async (): Promise<TwoFactorStatus> => {
      const { data } = await api.get<{ data: TwoFactorStatus }>('/two-factor');
      return data.data;
    },
    enabled: opts?.enabled ?? true,
  });
}

/** Begin setup. Calling it again before confirming rolls a fresh secret. */
export function useEnableTwoFactor() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<TwoFactorSetup> => {
      const { data } = await api.post<{ data: TwoFactorSetup }>('/two-factor/enable');
      return data.data;
    },
    // `pending` flips, so the status the screen renders from must be refetched.
    onSuccess: () => void qc.invalidateQueries({ queryKey: queryKeys.twoFactor() }),
  });
}

/** Prove the authenticator works. Returns the recovery codes — once. */
export function useConfirmTwoFactor() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (code: string): Promise<string[]> => {
      const { data } = await api.post<{ data: RecoveryCodes }>('/two-factor/confirm', { code });
      return data.data.recovery_codes;
    },
    onSuccess: () => void qc.invalidateQueries({ queryKey: queryKeys.twoFactor() }),
  });
}

/** Re-read the remaining codes. Costs a password. */
export function useRecoveryCodes() {
  return useMutation({
    mutationFn: async (password: string): Promise<string[]> => {
      const { data } = await api.post<{ data: RecoveryCodes }>('/two-factor/recovery-codes', { password });
      return data.data.recovery_codes;
    },
  });
}

/** Replace every code. Costs a password; invalidates all previous ones. */
export function useRegenerateRecoveryCodes() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (password: string): Promise<string[]> => {
      const { data } = await api.post<{ data: RecoveryCodes }>('/two-factor/recovery-codes/regenerate', { password });
      return data.data.recovery_codes;
    },
    // `recovery_codes_remaining` resets to the full count.
    onSuccess: () => void qc.invalidateQueries({ queryKey: queryKeys.twoFactor() }),
  });
}

/** Turn 2FA off. Costs a password. */
export function useDisableTwoFactor() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (password: string): Promise<void> => {
      await api.delete('/two-factor', { data: { password } });
    },
    onSuccess: () => void qc.invalidateQueries({ queryKey: queryKeys.twoFactor() }),
  });
}

export type ChallengeInput = { challenge_token: string; code?: string; recovery_code?: string };

/**
 * The second half of a 2FA login (T-068): trade the challenge token for a real
 * session.
 *
 * This is the one place outside `useAuth` that establishes a session, so it
 * performs the same three writes — token, session store, `me` cache — that
 * `onAuthenticated` does. Skipping the `me` write would leave the app signed in
 * but with an empty profile until the next fetch, and would break the offline
 * cold start (T-103), which restores from exactly that entry.
 */
export function useTwoFactorChallenge() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (input: ChallengeInput): Promise<AuthResponse> => {
      const { data } = await api.post<{ data: AuthResponse }>('/auth/two-factor-challenge', {
        ...input,
        device_name: deviceName(),
      });
      return data.data;
    },
    onSuccess: async ({ token, user }) => {
      await setToken(token);
      useSessionStore.getState().setUser(user);
      qc.setQueryData(queryKeys.me, user);
    },
  });
}
