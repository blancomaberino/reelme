import type { Locale } from '@/stores/settings';

import { apiWebUrl } from './web-urls';

/** The legal documents the API publishes (T-054). */
export type LegalDocument = 'privacy' | 'terms';

/**
 * The published URL of a legal document, in the language the app is currently
 * running in.
 *
 * The locale is PINNED into the path rather than left to the server's
 * content negotiation. A user who has set the app to English is telling us
 * which language they read; their browser's `Accept-Language` is a different
 * claim, made by a different piece of software, and following it would open the
 * privacy policy in Spanish for someone who chose English one screen earlier.
 *
 * Null when `EXPO_PUBLIC_API_URL` is unset — which is only ever a misconfigured
 * dev build, but a row that opens nothing is better than one that opens
 * `undefined/privacy/es`.
 */
export function legalUrl(doc: LegalDocument, locale: Locale): string | null {
  return apiWebUrl(`/${doc}/${locale}`);
}
