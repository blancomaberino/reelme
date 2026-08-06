/**
 * Build-time feature flags.
 *
 * Exactly one flag today, and the bar for adding a second is high: a flag is a
 * branch that ships, so every one of them is a code path nobody exercises until
 * somebody remembers it exists. This file is for the narrow case where the
 * client is finished but the server it calls is not — the UI can then be built,
 * reviewed and tested against its real endpoints in the same PR as the screen,
 * instead of being written from memory months later.
 *
 * Flags are read from `EXPO_PUBLIC_*` env at bundle time (Metro inlines them),
 * so flipping one is a build-config change, not a release-branch patch.
 */

/**
 * `undefined`/empty → the compiled-in default. Anything else must be an
 * explicit truthy word: a typo'd `EXPO_PUBLIC_FEATURE_X=yes` reads as OFF, which
 * is the safe direction for a flag guarding an irreversible action.
 */
export function parseFlag(raw: string | undefined, fallback: boolean): boolean {
  if (raw === undefined || raw === '') return fallback;
  return raw === '1' || raw.toLowerCase() === 'true';
}

export const featureFlags = {
  /**
   * GDPR self-service: the "export my data" and "delete my account" actions on
   * `settings/privacy`.
   *
   * OFF until **T-050** (M5) ships `POST /me/export` and `DELETE /me` — the
   * routes do not exist yet, so with this on, both actions 404. The privacy
   * screen still ships today: it explains both rights and shows them as not yet
   * available, which is the honest state, rather than hiding the fact that the
   * app holds this data at all.
   *
   * Turning it on is the whole M5 mobile change — the screen, the two mutations,
   * the confirm flow and the local-teardown-on-delete are already written and
   * tested behind it (`privacy-enabled.test.tsx` runs that path).
   */
  gdprSelfService: parseFlag(process.env.EXPO_PUBLIC_FEATURE_GDPR_SELF_SERVICE, false),
} as const;

export type FeatureFlag = keyof typeof featureFlags;
