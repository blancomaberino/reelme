import { useQueryClient } from '@tanstack/react-query';
import * as Notifications from 'expo-notifications';
import { type Href, router, usePathname } from 'expo-router';
import { useEffect } from 'react';

import { queryKeys } from '@/api/keys';
import { useSessionStore } from '@/stores/session';
import { useUiStore } from '@/stores/ui';

import {
  configureForegroundHandler,
  registerForPush,
  setCurrentPath,
  setupAndroidChannel,
} from './push';
import { dataFromNotification, dataFromResponse, shareIdFromData, urlFromData } from './routing';

// The deep-link is a validated in-app path (urlFromData enforces a leading `/`)
// resolved at runtime, so it can't be a compile-time typed-route literal.
function pushUrl(url: string): void {
  router.push(url as Href);
}

/**
 * Wires the push-notification leg (T-027) into the app tree. Mounted once in the
 * root layout:
 *  - configures the foreground banner handler + Android channel,
 *  - handles a cold-start tap (stage the deep-link until auth resolves),
 *  - navigates on a warm tap and live-updates an open share screen,
 *  - registers/refreshes the Expo token whenever the session is authed.
 */
export function usePushNotifications(): void {
  const qc = useQueryClient();
  const status = useSessionStore((s) => s.status);
  const pathname = usePathname();

  // Mirror the current route so the foreground handler can suppress a banner for
  // the screen the user is already on.
  useEffect(() => {
    setCurrentPath(pathname);
  }, [pathname]);

  // One-time setup + cold-start tap. `getLastNotificationResponseAsync` returns
  // the tap that launched the app; stage it until the auth gate resolves (the
  // navigator isn't ready yet), or push immediately if already authed.
  useEffect(() => {
    configureForegroundHandler();
    void setupAndroidChannel();

    let active = true;
    void (async () => {
      try {
        const response = await Notifications.getLastNotificationResponseAsync();
        if (!active) return;
        const url = urlFromData(dataFromResponse(response));
        if (!url) return;
        if (useSessionStore.getState().status === 'authed') {
          pushUrl(url);
        } else {
          useUiStore.getState().setPendingNotificationUrl(url);
        }
      } catch {
        // best-effort: a failed cold-start read must not crash startup
      }
    })();

    return () => {
      active = false;
    };
  }, []);

  // Foreground arrival: refresh the target share so an open status/review screen
  // updates instantly (the banner itself is handled by the handler above).
  useEffect(() => {
    const sub = Notifications.addNotificationReceivedListener((notification) => {
      // The id rides in `data` for every share push (incl. `published`, whose url
      // is /place/… and has no id to parse) so an open share screen live-updates.
      const id = shareIdFromData(dataFromNotification(notification));
      if (id) void qc.invalidateQueries({ queryKey: queryKeys.share(id) });
    });
    return () => sub.remove();
  }, [qc]);

  // Warm tap: the URL is the route.
  useEffect(() => {
    const sub = Notifications.addNotificationResponseReceivedListener((response) => {
      const url = urlFromData(dataFromResponse(response));
      if (url) pushUrl(url);
    });
    return () => sub.remove();
  }, []);

  // Register on every authed session (first authed launch and after login), and
  // flush a cold-start deep-link staged before auth resolved.
  useEffect(() => {
    if (status !== 'authed') return;
    void registerForPush();

    const pending = useUiStore.getState().pendingNotificationUrl;
    if (pending) {
      useUiStore.getState().setPendingNotificationUrl(null);
      pushUrl(pending);
    }
  }, [status]);
}
