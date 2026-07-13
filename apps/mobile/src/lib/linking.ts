import { Linking } from 'react-native';

/** True for http(s) URLs — the only schemes we open from API-supplied strings. */
export function isHttpUrl(url: string | null | undefined): url is string {
  return typeof url === 'string' && /^https?:\/\//i.test(url);
}

/**
 * Open a URL, swallowing the rejection `Linking.openURL` throws when the OS has
 * no handler (e.g. `tel:` on a Wi-Fi-only iPad, a malformed URL). Callers that
 * pass API-supplied strings should gate on {@link isHttpUrl} first — this only
 * guarantees no unhandled promise rejection, not scheme safety.
 */
export async function openExternal(url: string): Promise<void> {
  try {
    await Linking.openURL(url);
  } catch {
    // No app can handle it, or the URL is malformed — nothing actionable for
    // the user beyond the tap doing nothing. (A toast would be a nicer M3 touch.)
  }
}

/** Open an API-supplied web URL only when it is genuinely http(s). */
export function openWebUrl(url: string | null | undefined): void {
  if (isHttpUrl(url)) {
    void openExternal(url);
  }
}
