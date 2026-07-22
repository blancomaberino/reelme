import type { Notification, NotificationResponse } from 'expo-notifications';

import {
  dataFromNotification,
  dataFromResponse,
  isOnTargetRoute,
  shareIdFromUrl,
  urlFromData,
} from '../routing';

function response(data: unknown): NotificationResponse {
  return { notification: { request: { content: { data } } } } as unknown as NotificationResponse;
}

describe('notification routing helpers', () => {
  it('extracts the data bag from a response and a raw notification', () => {
    const data = { type: 'share.published', url: '/place/tortoni' };
    expect(dataFromResponse(response(data))).toEqual(data);
    expect(
      dataFromNotification({ request: { content: { data } } } as unknown as Notification),
    ).toEqual(data);
  });

  it('returns null for a malformed or missing data bag', () => {
    expect(dataFromResponse(null)).toBeNull();
    expect(dataFromResponse(response(undefined))).toBeNull();
    expect(dataFromNotification(null)).toBeNull();
  });

  it('only accepts an in-app path as the deep-link url', () => {
    expect(urlFromData({ url: '/shares/7/review' })).toBe('/shares/7/review');
    // Guard against a non-route payload becoming a router.push target.
    expect(urlFromData({ url: 'https://evil.example' })).toBeNull();
    expect(urlFromData({ url: '' })).toBeNull();
    expect(urlFromData({})).toBeNull();
    expect(urlFromData(null)).toBeNull();
  });

  it('pulls the share id out of a /shares/:id deep-link', () => {
    expect(shareIdFromUrl('/shares/42/status')).toBe('42');
    expect(shareIdFromUrl('/shares/42/review')).toBe('42');
    expect(shareIdFromUrl('/place/tortoni')).toBeNull();
  });

  it('suppresses a foreground banner only on the exact target route', () => {
    expect(isOnTargetRoute('/shares/7/status', '/shares/7/status')).toBe(true);
    // Trailing slash / query string ignored.
    expect(isOnTargetRoute('/shares/7/status?x=1', '/shares/7/status/')).toBe(true);
    expect(isOnTargetRoute('/shares/7/status', '/shares/8/status')).toBe(false);
    expect(isOnTargetRoute(null, '/shares/7/status')).toBe(false);
    expect(isOnTargetRoute('/shares/7/status', null)).toBe(false);
  });
});
