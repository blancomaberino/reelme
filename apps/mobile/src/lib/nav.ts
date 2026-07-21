import { router } from 'expo-router';

/**
 * Go back if there's history, else land on the map (the home tab). Screens opened
 * fresh — a deep link, a push notification (T-027), or after a `router.replace`
 * that consumed the back entry — have no stack to pop, so a bare `router.back()`
 * throws "GO_BACK was not handled by any navigator" and traps the user.
 */
export function safeBack(): void {
  if (router.canGoBack()) {
    router.back();
  } else {
    router.replace('/(main)/map');
  }
}
