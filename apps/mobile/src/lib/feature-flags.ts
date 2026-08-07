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
   * ON since **T-050** shipped `POST /me/export` and `DELETE /me`. Kept as a
   * flag rather than deleted because it is the switch that takes the two
   * actions down cleanly — showing "not available yet" instead of a 404 — if the
   * backend ever has to be rolled back. It also stays because Apple requires
   * in-app account deletion (Guideline 5.1.1(v)), so a build that ships this
   * OFF is a build that fails review: the default is the thing to protect.
   */
  gdprSelfService: parseFlag(process.env.EXPO_PUBLIC_FEATURE_GDPR_SELF_SERVICE, true),
} as const;

export type FeatureFlag = keyof typeof featureFlags;
