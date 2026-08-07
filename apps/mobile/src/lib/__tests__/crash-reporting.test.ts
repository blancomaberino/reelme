import { __resetCrashReportingForTests, initCrashReporting, reportError } from '../crash-reporting';

/**
 * Crash reporting (T-090, completed in T-052).
 *
 * Two properties matter and they pull in opposite directions: when a DSN is
 * configured the reporter must genuinely initialise **with a release tag**, and
 * when anything about that goes wrong it must fail silently — this code runs at
 * app boot, before any error boundary exists to catch it.
 */
// `mock`-prefixed: jest hoists `jest.mock()` above every const, and only that
// prefix is allowed to be referenced from the factory.
const mockSentry = {
  init: jest.fn(),
  captureException: jest.fn(),
};

// Mocked because the real SDK is ESM and jest's transform rejects it. That is
// worth stating: before T-052 this file had no mock, the require() threw on the
// transform, and the "degrades when the native SDK is absent" test below passed
// for a reason that had nothing to do with the native module — while the init
// path it was meant to bracket was never executed at all.
jest.mock('@sentry/react-native', () => mockSentry, { virtual: true });

const ORIGINAL_DSN = process.env.EXPO_PUBLIC_SENTRY_DSN;

beforeEach(() => {
  mockSentry.init.mockReset();
  mockSentry.captureException.mockReset();
});

afterEach(() => {
  __resetCrashReportingForTests();
  if (ORIGINAL_DSN === undefined) {
    delete process.env.EXPO_PUBLIC_SENTRY_DSN;
  } else {
    process.env.EXPO_PUBLIC_SENTRY_DSN = ORIGINAL_DSN;
  }
});

it('is a no-op without a DSN — never initialises a reporter or throws', () => {
  delete process.env.EXPO_PUBLIC_SENTRY_DSN;
  const spy = jest.spyOn(console, 'error').mockImplementation(() => {});

  expect(() => initCrashReporting()).not.toThrow();
  // With no reporter configured, reporting falls back to a dev console log and
  // must never throw (telemetry can't be allowed to crash the app).
  expect(() => reportError(new Error('boom'), { where: 'test' })).not.toThrow();
  expect(spy).toHaveBeenCalled();
  // And the SDK is never even loaded — CI and local dev do not touch it.
  expect(mockSentry.init).not.toHaveBeenCalled();

  spy.mockRestore();
});

it('tags every event with the release that produced it', () => {
  process.env.EXPO_PUBLIC_SENTRY_DSN = 'https://public@example.test/1';

  initCrashReporting();

  const options = mockSentry.init.mock.calls[0][0];

  // Without a release every regression reads as "always been broken", and "did
  // the last update cause this" — the first question anyone asks — has no
  // answer. `<bundleId>@<version>` is Sentry's own convention.
  expect(options.release).toMatch(/^pet\.one\.reelmap@\d+\.\d+\.\d+$/);
  expect(options.dsn).toBe('https://public@example.test/1');
  expect(options.enableNative).toBe(true);
});

it('does not attach PII', () => {
  process.env.EXPO_PUBLIC_SENTRY_DSN = 'https://public@example.test/1';

  initCrashReporting();

  // Mirrors the API side. T-050 built erasure guarantees over this data, and a
  // copy inside a third-party tracker is outside every one of them — `DELETE
  // /me` cannot reach it. The SDK defaults this off; the assertion is that
  // nothing here turns it on.
  expect(mockSentry.init.mock.calls[0][0].sendDefaultPii).toBeUndefined();
});

it('forwards a boundary-caught error with its context', () => {
  process.env.EXPO_PUBLIC_SENTRY_DSN = 'https://public@example.test/1';
  initCrashReporting();

  const error = new Error('render exploded');
  reportError(error, { componentStack: 'at MapScreen' });

  expect(mockSentry.captureException).toHaveBeenCalledWith(error, {
    extra: { componentStack: 'at MapScreen' },
  });
});

it('initialises once, however many times boot runs', () => {
  process.env.EXPO_PUBLIC_SENTRY_DSN = 'https://public@example.test/1';

  initCrashReporting();
  initCrashReporting();

  // Fast Refresh re-evaluates the root layout, and a second `Sentry.init` would
  // replace a live client mid-session.
  expect(mockSentry.init).toHaveBeenCalledTimes(1);
});

it('degrades to a no-op when the SDK itself throws on init', () => {
  process.env.EXPO_PUBLIC_SENTRY_DSN = 'https://public@example.test/1';
  mockSentry.init.mockImplementation(() => {
    throw new Error('native module missing');
  });
  const warn = jest.spyOn(console, 'warn').mockImplementation(() => {});

  // The real case this guards: a dev client built before the SDK was added.
  // Telemetry setup must never crash the app it exists to observe, and this
  // runs at boot — before any error boundary is mounted to catch it.
  expect(() => initCrashReporting()).not.toThrow();
  expect(() => reportError(new Error('boom'))).not.toThrow();
  expect(mockSentry.captureException).not.toHaveBeenCalled();

  warn.mockRestore();
});
