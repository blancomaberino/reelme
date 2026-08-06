import { useMutation, useQueryClient } from '@tanstack/react-query';

import { registerForPush, unregisterPush } from '@/notifications/push';

import { api } from '../client';
import { clearLocalSession } from './useAuth';

/**
 * The two GDPR rights the app exposes (T-039 privacy screen, 05 screen #16).
 *
 * Both call endpoints that **T-050 has not built yet**, which is why every
 * caller sits behind `featureFlags.gdprSelfService`. They are written now, with
 * the screen, because the shapes are pinned by 03 §2 and the interesting part is
 * not the request — it is what the client does around it, and that is easier to
 * get right next to the UI than alone in M5.
 */

/**
 * Ask for a copy of everything the account holds.
 *
 * Fire-and-forget by design: the archive is assembled by a queued job and
 * delivered by email, so there is nothing to poll and no artifact to hand back
 * to the screen. A 202 means "we heard you", not "here is your data".
 */
export function useExportMyData() {
  return useMutation({
    mutationFn: async (): Promise<void> => {
      await api.post('/me/export');
    },
  });
}

/**
 * Delete the account, then erase it from this device.
 *
 * Ordering matters and is not interchangeable with sign-out's:
 *
 * 1. `unregisterPush` runs FIRST because it is an authed DELETE — after the
 *    account is gone its token is invalid, and this install would keep its
 *    device row alive on a user that no longer exists.
 * 2. The local wipe runs ONLY on a successful response. Sign-out clears
 *    regardless (an offline sign-out is still a sign-out), but a failed delete
 *    must leave the session intact — signing someone out of an account that
 *    still exists, while telling them it was deleted, is the worst outcome here.
 */
export function useDeleteAccount() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (): Promise<void> => {
      await unregisterPush();
      await api.delete('/me');
    },
    onSuccess: () => clearLocalSession(qc),
    // Step 1 already ran by the time we get here, so "leave the account intact"
    // is not automatic — without this, a user who tapped delete and got a 500
    // stays signed in but silently stops receiving notifications. Re-registering
    // is silent when permission is already granted (no second OS prompt) and is
    // best-effort internally, so this cannot turn one failure into two.
    onError: () => registerForPush(),
  });
}
