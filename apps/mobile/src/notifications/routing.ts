// Pure notification-routing helpers (T-027). The whole routing contract is
// `data.url` — an in-app path the tap handler passes straight to `router.push`
// (05 §5.2), so there is no per-type switch. Kept side-effect-free for testing.

import type { Notification, NotificationResponse } from 'expo-notifications';

export type NotificationData = { type?: string; url?: string; share_id?: number | string };

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
  // Must be a leading-slash in-app path, but NOT a protocol-relative `//host`
  // (which would resolve to another origin) — defense in depth on router.push.
  return typeof url === 'string' && url.startsWith('/') && !url.startsWith('//') ? url : null;
}

/** The share id from a notification's data (as a string for the query key). */
export function shareIdFromData(data: NotificationData | null): string | null {
  const id = data?.share_id;
  return id === undefined || id === null || id === '' ? null : String(id);
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
