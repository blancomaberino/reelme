/**
 * Building absolute URLs onto the API's own web origin.
 *
 * The API serves a handful of human-facing pages alongside the JSON API — the
 * shared-list page (T-063) and the legal documents (T-054) — and every one of
 * them is reached by gluing a path onto `EXPO_PUBLIC_API_URL`. Extracted here so
 * that gluing happens once: the trailing-slash normalisation and the
 * "unconfigured in dev" null are the parts that go wrong, and two copies of them
 * would eventually disagree about which.
 */
export function apiWebUrl(path: string, base = process.env.EXPO_PUBLIC_API_URL): string | null {
  if (!base) return null;

  return `${base.replace(/\/+$/, '')}/${path.replace(/^\/+/, '')}`;
}
