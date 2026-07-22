import { useMutation, useQueryClient } from '@tanstack/react-query';
import * as Device from 'expo-device';

import { unregisterPush } from '@/notifications/push';
import { useMapStore } from '@/stores/map';
import { useSessionStore } from '@/stores/session';

import { api } from '../client';
import { queryKeys } from '../keys';
import { clearToken, setToken } from '../token';
import type { AuthResponse } from '../types';

export type RegisterInput = { name: string; username: string; email: string; password: string };
export type LoginInput = { email: string; password: string };

// The API issues one token per device and revokes a same-named token, so a
// stable device_name is required by the register/login contract (03 §2.1).
function deviceName(): string {
  return Device.deviceName ?? 'mobile';
}

async function authenticate(path: string, body: Record<string, unknown>): Promise<AuthResponse> {
  const { data } = await api.post<{ data: AuthResponse }>(path, { ...body, device_name: deviceName() });
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
    onSuccess: async () => {
      await clearToken();
      useSessionStore.getState().clear();
      // Drop any authed-only map scope (following/mine) so the now-guest map
      // doesn't send a filter that 401s (T-039).
      useMapStore.getState().clearFilters();
      qc.clear();
    },
  });
}
