/**
 * App-wide crash reporting (T-090, completed in T-052).
 *
 * Env-gated so it is a pure no-op without a DSN — CI, tests, and local dev never
 * initialise a reporter or hit the network. When `EXPO_PUBLIC_SENTRY_DSN` is set
 * (release builds), {@link initCrashReporting} loads `@sentry/react-native` and
 * forwards uncaught render errors (via the top-level ErrorBoundary) and NATIVE
 * crashes to it.
 *
 * The require stays lazy even now that the SDK is a real dependency. Two
 * reasons, and neither is about bundle size: a build whose native module is
 * missing (a stale dev client that predates the rebuild) degrades to the no-op
 * path instead of throwing at module-eval time, before any error boundary
 * exists to catch it; and every test in the suite would otherwise pull in the
 * native shim.
 *
 * PII: `sendDefaultPii` is left off (the SDK's default) and nothing here
 * attaches a user. Mirrors the API side — T-050 built erasure guarantees over
 * this data and a copy in a third-party tracker is outside all of them.
 *
 * T-090's activation note recommended pinning 8.19.0. That was wrong for this
 * SDK: `expo install` resolves ~7.11 for Expo 57 and `expo install --check`
 * agrees. Trust the installer over a hand-written pin.
 *
 * The SDK version bump is a NATIVE change — `npx expo prebuild --clean` plus a
 * dev-client rebuild. A Metro restart will not pick it up.
 */

import Constants from 'expo-constants';

type Reporter = {
  captureException: (error: unknown, context?: Record<string, unknown>) => void;
};

let reporter: Reporter | null = null;

/** Call once at app start. No-op unless a Sentry DSN is configured. */
export function initCrashReporting(): void {
  const dsn = process.env.EXPO_PUBLIC_SENTRY_DSN;
  if (!dsn || reporter !== null) {
    return; // env-gated no-op (no DSN → CI/dev/local), or already initialised
  }

  try {
    // Lazy require: the native SDK is loaded ONLY when actually configured, so a
    // no-DSN build (and every test) never pulls it in.
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const Sentry = require('@sentry/react-native') as {
      init: (options: Record<string, unknown>) => void;
      captureException: (error: unknown, hint?: { extra?: Record<string, unknown> }) => void;
    };
    Sentry.init({
      dsn,
      enableNative: true,
      // Performance tracing off: this is a crash reporter, and sampling traces
      // from a mobile client costs battery and quota for data nothing here
      // reads yet.
      tracesSampleRate: 0,
      /*
       * Release tagging (T-052). Without it every regression reads as "always
       * been broken", and "did the last update cause this" — the first question
       * anyone asks — has no answer.
       *
       * `<bundleId>@<version>` is Sentry's convention. Read from
       * `Constants.expoConfig`, which the push registration already uses as the
       * app's version source (src/notifications/push.ts) — rather than adding
       * expo-application for a string the app can already state.
       *
       * FOLLOW-UP, once OTA is actually live: `app.config.ts` declares an
       * `updates.url` but **expo-updates is not installed**, so today one store
       * build serves exactly one JS bundle and the version alone identifies it.
       * When that changes, a single version will span many bundles and lump
       * them together — set `dist: Updates.updateId` at that point.
       */
      release: `pet.one.reelmap@${Constants.expoConfig?.version ?? '0.0.0'}`,
      environment: __DEV__ ? 'development' : 'production',
    });
    reporter = {
      captureException: (error, context) =>
        Sentry.captureException(error, context ? { extra: context } : undefined),
    };
  } catch (error) {
    // Telemetry setup must never crash the app it exists to observe.
    if (__DEV__) {
      console.warn('[crash-reporting] init failed', error);
    }
  }
}

/**
 * Report a handled/boundary-caught error. Forwards to the configured reporter,
 * else logs in dev and silently drops in production (no DSN = nothing to send).
 */
export function reportError(error: unknown, context?: Record<string, unknown>): void {
  if (reporter !== null) {
    reporter.captureException(error, context);

    return;
  }

  if (__DEV__) {
    console.error('[crash-reporting]', error, context);
  }
}

/** Test-only: reset the module's reporter between cases. */
export function __resetCrashReportingForTests(): void {
  reporter = null;
}
