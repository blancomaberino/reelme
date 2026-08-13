import { legalUrl } from '../legal';
import { apiWebUrl } from '../web-urls';

const ORIGINAL = process.env.EXPO_PUBLIC_API_URL;

afterEach(() => {
  // `process.env` is shared across every suite in a jest worker, and assigning
  // an undefined original back stores the STRING "undefined" — which is truthy,
  // so a later suite reading it would build `undefined/privacy/es` instead of
  // taking the unset branch. Restore by deleting when there was nothing there.
  if (ORIGINAL === undefined) {
    delete process.env.EXPO_PUBLIC_API_URL;
  } else {
    process.env.EXPO_PUBLIC_API_URL = ORIGINAL;
  }
});

it('pins the locale into the published document URL', () => {
  process.env.EXPO_PUBLIC_API_URL = 'https://api.reelmap.app';

  expect(legalUrl('privacy', 'es')).toBe('https://api.reelmap.app/privacy/es');
  expect(legalUrl('privacy', 'en')).toBe('https://api.reelmap.app/privacy/en');
  expect(legalUrl('terms', 'es')).toBe('https://api.reelmap.app/terms/es');
  expect(legalUrl('terms', 'en')).toBe('https://api.reelmap.app/terms/en');
});

it('does not build a URL against an unconfigured origin', () => {
  // A row that opens nothing beats one that opens `undefined/privacy/es`.
  delete process.env.EXPO_PUBLIC_API_URL;

  expect(legalUrl('privacy', 'es')).toBeNull();
  expect(legalUrl('terms', 'en')).toBeNull();
});

it('normalises a trailing slash on the configured origin', () => {
  // `https://api.reelmap.app//privacy/es` is a different path to the server and
  // would 404 the one document a reviewer is guaranteed to open.
  process.env.EXPO_PUBLIC_API_URL = 'https://api.reelmap.app/';

  expect(legalUrl('privacy', 'es')).toBe('https://api.reelmap.app/privacy/es');
});

it('joins paths without doubling or dropping the separator', () => {
  expect(apiWebUrl('/l/abc', 'https://api.reelmap.app')).toBe('https://api.reelmap.app/l/abc');
  expect(apiWebUrl('l/abc', 'https://api.reelmap.app')).toBe('https://api.reelmap.app/l/abc');
  expect(apiWebUrl('/l/abc', 'https://api.reelmap.app///')).toBe('https://api.reelmap.app/l/abc');
  expect(apiWebUrl('/l/abc', '')).toBeNull();
});
