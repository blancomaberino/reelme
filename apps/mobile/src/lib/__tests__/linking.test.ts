import { Linking } from 'react-native';

import { isHttpUrl, openExternal, openWebUrl } from '../linking';

// jest-expo's Linking.openURL is a persistent mock — reset its call log per test.
beforeEach(() => jest.clearAllMocks());

describe('isHttpUrl', () => {
  it('accepts http and https only', () => {
    expect(isHttpUrl('https://a.com')).toBe(true);
    expect(isHttpUrl('http://a.com')).toBe(true);
    expect(isHttpUrl('HTTPS://A.com')).toBe(true);
  });
  it('rejects other schemes and empties', () => {
    expect(isHttpUrl('file:///etc/passwd')).toBe(false);
    expect(isHttpUrl('javascript:alert(1)')).toBe(false);
    expect(isHttpUrl('tel:+123')).toBe(false);
    expect(isHttpUrl(null)).toBe(false);
    expect(isHttpUrl(undefined)).toBe(false);
    expect(isHttpUrl('')).toBe(false);
  });
});

describe('openExternal', () => {
  afterEach(() => jest.restoreAllMocks());

  it('swallows a rejection from Linking.openURL (no handler)', async () => {
    jest.spyOn(Linking, 'openURL').mockRejectedValue(new Error('no handler'));
    await expect(openExternal('tel:+123')).resolves.toBeUndefined();
  });
});

describe('openWebUrl', () => {
  afterEach(() => jest.restoreAllMocks());

  it('opens an http(s) URL', () => {
    const spy = jest.spyOn(Linking, 'openURL').mockResolvedValue(true);
    openWebUrl('https://example.com');
    expect(spy).toHaveBeenCalledWith('https://example.com');
  });

  it('refuses a non-http scheme', () => {
    const spy = jest.spyOn(Linking, 'openURL').mockResolvedValue(true);
    openWebUrl('file:///etc/passwd');
    openWebUrl(null);
    expect(spy).not.toHaveBeenCalled();
  });
});
