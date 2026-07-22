// Pure notification-routing helpers (T-027). The whole routing contract is
// `data.url` — an in-app path the tap handler passes straight to `router.push`
// (05 §5.2), so there is no per-type switch. Kept side-effect-free for testing.

import type { Notification, NotificationResponse } from 'expo-notifications';

export type NotificationData = { type?: string; url?: string };

/** The `data` bag off a delivered notification, or null if malformed. */
export function dataFromNotification(notification: Notification | null): NotificationData | null {
  const data = notification?.request?.content?.data;
  return data && typeof data === 'object' ? (data as NotificationData) : null;
}

/** The `data` bag off a tapped notification response, or null if malformed. */
export function dataFromResponse(response: NotificationResponse | null): NotificationData | null {
  return dataFromNotification(response?.notification ?? null);
}

/** An in-app path from a notification's data, or null unless it's an app route. */
export function urlFromData(data: NotificationData | null): string | null {
  const url = data?.url;
  return typeof url === 'string' && url.startsWith('/') ? url : null;
}

/** The share id embedded in a `/shares/:id/...` deep-link, for cache invalidation. */
export function shareIdFromUrl(url: string): string | null {
  const match = /^\/shares\/([^/]+)/.exec(url);
  return match ? match[1] : null;
}

/**
 * True when a foreground notification targets the screen the user is already on,
 * so the banner should be suppressed (query strings and a trailing slash are
 * ignored so `/shares/1/status` matches regardless of params).
 */
export function isOnTargetRoute(dataUrl: string | null, currentPath: string | null): boolean {
  if (!dataUrl || !currentPath) return false;
  return stripPath(dataUrl) === stripPath(currentPath);
}

function stripPath(path: string): string {
  return path.split('?')[0].replace(/\/+$/, '');
}
