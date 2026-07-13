import { getShareExtensionKey } from 'expo-share-intent';

/**
 * expo-router deep-link guard for the iOS share extension.
 *
 * When a link/text is shared into Reelmap, the extension opens the host app with
 * `reelmap://dataUrl=<shareKey>?nonce=…#weburl`. That path matches no route, so
 * without this hook expo-router shows "Unmatched Route". We detect the share
 * key and send the app to the root instead — the ShareIntentProvider reads the
 * payload from the native module and `ShareIntentRedirect` (in the root layout)
 * routes to the Share tab with the shared URL/text prefilled.
 */
export function redirectSystemPath({ path, initial }: { path: string; initial: boolean }): string {
  try {
    if (path.includes(`dataUrl=${getShareExtensionKey()}`)) {
      return '/';
    }
    return path;
  } catch {
    return initial ? '/' : path;
  }
}
