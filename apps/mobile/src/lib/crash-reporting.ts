/**
 * App-wide crash reporting (T-090), env-gated so it is a pure no-op without a
 * DSN — CI, tests, and local dev never initialise a reporter or hit the network.
 *
 * When `EXPO_PUBLIC_SENTRY_DSN` is set (release builds), {@link initCrashReporting}
 * lazily loads `@sentry/react-native` and forwards uncaught render errors (via
 * the top-level ErrorBoundary) and native crashes to it. The lazy require means
 * the native SDK is only touched when a DSN is actually configured; a build
 * without the native module simply degrades to the no-op path.
 *
 * Activating native-crash capture (a follow-up needing a native rebuild, which
 * is why the SDK isn't a dependency yet — see T-090 PR):
 *   1. `npm i -w @reelmap/mobile @sentry/react-native@8.19.0` (exact pin; the
 *      current SDK-57-compatible release — avoid a caret + 8.17.0/.1).
 *   2. Add the `@sentry/react-native/expo` config plugin to app.config.ts with
 *      the real Sentry org/project (auth token via EAS secret, never committed).
 *   3. Set `EXPO_PUBLIC_SENTRY_DSN` and rebuild the dev client (`./scripts/dev.sh`)
 *      — a Metro-only restart won't pick up the native module.
 * This module's require() target already matches, so no code change is needed.
 */

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
    Sentry.init({ dsn, enableNative: true, tracesSampleRate: 0 });
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
