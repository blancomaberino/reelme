import { useMutation, useQueryClient } from '@tanstack/react-query';
import * as Device from 'expo-device';

import { clearPersistedQueryCache } from '@/lib/query-persist';
import { unregisterPush } from '@/notifications/push';
import { useMapStore } from '@/stores/map';
import { useSessionStore } from '@/stores/session';
import { useViewportStore } from '@/stores/viewport';

import { api } from '../client';
import { queryKeys } from '../keys';
import { clearToken, setToken } from '../token';
import { type AuthResponse, type LoginResponse, isTwoFactorChallenge, TwoFactorRequiredError } from '../types';

export type RegisterInput = { name: string; username: string; email: string; password: string };
export type LoginInput = { email: string; password: string };

// The API issues one token per device and revokes a same-named token, so a
// stable device_name is required by the register/login contract (03 §2.1).
// Exported because the 2FA challenge (T-068) also mints a session and must use
// the SAME name — a different one would leave the pre-2FA token alive on this
// device instead of replacing it.
export function deviceName(): string {
  return Device.deviceName ?? 'mobile';
}

async function authenticate(path: string, body: Record<string, unknown>): Promise<AuthResponse> {
  const { data } = await api.post<{ data: LoginResponse }>(path, { ...body, device_name: deviceName() });

  // A 2FA account gets a challenge, not a session (T-068). Surface it as a
  // typed error rather than letting a token-less payload reach
  // `onAuthenticated`, which would persist `undefined` as the credential.
  if (isTwoFactorChallenge(data.data)) {
    throw new TwoFactorRequiredError(data.data.challenge_token);
  }

  return data.data;
}

async function onAuthenticated(qc: ReturnType<typeof useQueryClient>, { token, user }: AuthResponse) {
  await setToken(token);
  useSessionStore.getState().setUser(user);
  qc.setQueryData(queryKeys.me, user);
}

export function useRegister() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: RegisterInput) => authenticate('/auth/register', input),
    onSuccess: (res) => onAuthenticated(qc, res),
  });
}

export function useLogin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: LoginInput) => authenticate('/auth/login', input),
    onSuccess: (res) => onAuthenticated(qc, res),
  });
}

export type VerifyEmailInput = { email: string; code: string };

/** Confirm the account with the emailed 6-digit code, then log in (T-066). */
export function useVerifyEmail() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: VerifyEmailInput) => authenticate('/auth/verify-email', input),
    onSuccess: (res) => onAuthenticated(qc, res),
  });
}

/** Re-send the email confirmation code (T-066). Always resolves (no enumeration). */
export function useResendVerification() {
  return useMutation({
    mutationFn: (email: string) => api.post('/auth/resend-verification', { email }),
  });
}

/**
 * Wipe every trace of the signed-in account from this device.
 *
 * Shared by sign-out and account deletion (T-039 privacy screen) rather than
 * copied: the list below is not obvious — it grew one incident at a time (push
 * token, map scope, remembered viewport, on-disk query cache) — and a second
 * copy would inherit today's list and none of tomorrow's additions. Deletion in
 * particular must clear strictly MORE than sign-out, never less.
 */
export async function clearLocalSession(qc: ReturnType<typeof useQueryClient>): Promise<void> {
  // In-memory and infallible, so they go FIRST: if one of the persistent wipes
  // below throws, the app must still stop presenting itself as signed in.
  useSessionStore.getState().clear();
  // Drop any authed-only map scope (following/mine) so the now-guest map
  // doesn't send a filter that 401s (T-039).
  useMapStore.getState().clearFilters();
  qc.clear();

  // Each remaining step reaches a DIFFERENT persistent store (Keychain,
  // AsyncStorage), and one being unreachable must not strand the others. This
  // used to be a straight `await` chain, so a throwing `clearToken()` skipped
  // everything after it — leaving both the token in the Keychain (the next
  // launch reads it back and signs you in again) and the on-disk query cache,
  // which is this account's private collection in plaintext, for whoever picks
  // up the device next.
  //
  // Sequential rather than Promise.allSettled: the persisted-cache wipe has to
  // stay AFTER `qc.clear()` so it cannot race a persist write scheduled by it.
  const wipes: (() => Promise<void>)[] = [
    clearToken,
    // Forget the remembered viewport (T-100) — it is coarse location data, so
    // the next person to sign in on this device must not open on the previous
    // user's last map position.
    () => useViewportStore.getState().clear(),
    // …and the on-disk copy of the cache (T-103).
    clearPersistedQueryCache,
  ];

  for (const wipe of wipes) {
    try {
      await wipe();
    } catch {
      // Best-effort per store: a wipe we cannot perform must not cancel the
      // wipes we can.
    }
  }
}

export function useLogout() {
  const qc = useQueryClient();
  return useMutation({
    // Clear locally even if the network call fails (device may be offline).
    mutationFn: async () => {
      // Unregister this device's push token FIRST — the DELETE is authed, so it
      // must run before the token is revoked, else this install keeps receiving
      // the previous user's pushes (T-027).
      await unregisterPush();
      try {
        await api.post('/auth/logout');
      } catch {
        // ignore — we clear the session regardless
      }
    },
    onSuccess: () => clearLocalSession(qc),
  });
}
