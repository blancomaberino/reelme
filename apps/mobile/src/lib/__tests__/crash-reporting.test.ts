import { __resetCrashReportingForTests, initCrashReporting, reportError } from '../crash-reporting';

const ORIGINAL_DSN = process.env.EXPO_PUBLIC_SENTRY_DSN;

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

  spy.mockRestore();
});

it('degrades gracefully when a DSN is set but the native SDK is absent', () => {
  // In CI the @sentry/react-native native module isn't installed; init must
  // catch the require() failure and stay a no-op rather than crash boot.
  process.env.EXPO_PUBLIC_SENTRY_DSN = 'https://public@example.test/1';
  const warn = jest.spyOn(console, 'warn').mockImplementation(() => {});

  expect(() => initCrashReporting()).not.toThrow();
  expect(() => reportError(new Error('boom'))).not.toThrow();

  warn.mockRestore();
});
