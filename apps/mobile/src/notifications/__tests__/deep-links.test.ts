import { readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Every path a notification can deep-link to must be a route this app has.
 *
 * `data.url` is handed straight to `router.push` by BOTH the center row and the
 * push tap handler, so a path with no route behind it dead-ends on Expo
 * Router's unmatched-route screen. Nothing catches that: the API test asserts
 * the string it emits, the screen test asserts `router.push` was called with
 * it, and `router` is mocked — every layer is green while the notification is
 * unusable. Two shipped that way, `/redemptions/{id}` and `/influencers/{id}`
 * (plural, against a singular segment).
 *
 * This is the mobile half of that contract: the API pins the exact URLs it
 * emits, and this pins that those shapes resolve against the real route tree.
 * Both halves have to change together for a deep-link to move.
 */

const APP_DIR = join(__dirname, '..', '..', '..', 'app');

/** Every route path Expo Router derives from the `app/` tree. */
function routePaths(dir: string, prefix = ''): string[] {
  const out: string[] = [];

  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);

    if (statSync(full).isDirectory()) {
      if (entry === '__tests__') continue;
      // A `(group)` segment is organisational — it contributes no URL segment.
      const segment = entry.startsWith('(') && entry.endsWith(')') ? prefix : `${prefix}/${entry}`;
      out.push(...routePaths(full, segment));
      continue;
    }

    if (!entry.endsWith('.tsx') || entry.startsWith('_') || entry.startsWith('+')) continue;

    const name = entry.replace(/\.tsx$/, '');
    out.push(name === 'index' ? prefix || '/' : `${prefix}/${name}`);
  }

  return out;
}

/** Does `path` match a route, treating `[param]` segments as wildcards? */
function resolves(path: string, routes: string[]): boolean {
  // Query string is not part of the route match.
  const segments = path.split('?')[0].split('/').filter(Boolean);

  return routes.some((route) => {
    const routeSegments = route.split('/').filter(Boolean);
    if (routeSegments.length !== segments.length) return false;

    return routeSegments.every(
      (segment, i) => (segment.startsWith('[') && segment.endsWith(']')) || segment === segments[i],
    );
  });
}

/**
 * One example URL per notification type, in the exact shape the API builds it
 * (see `NotificationCopyTest::deep-links to paths that exist in the mobile
 * router`). Concrete ids on purpose — a pattern with the parameter left in
 * would match a literal segment and prove nothing.
 */
const NOTIFICATION_URLS: Record<string, string> = {
  'share.published': '/place/bar-tinta-x3ojjv',
  'share.review_needed': '/shares/12/review',
  'share.failed': '/shares/12/status',
  'social.follow': '/users/ana',
  'influencer.claim_rejected': '/influencer/7',
  'redemption.verified': '/offers/3/redeem?redemptionId=9',
  'wallet.payout': '/wallet',
};

const routes = routePaths(APP_DIR);

it('finds the route tree', () => {
  // Guards the test itself: a wrong APP_DIR would make every assertion below
  // pass vacuously... or fail confusingly. Either way this says which.
  expect(routes).toContain('/notifications');
  expect(routes.length).toBeGreaterThan(20);
});

describe('every notification deep-link resolves to a real screen', () => {
  it.each(Object.entries(NOTIFICATION_URLS))('%s → %s', (_type, url) => {
    expect(resolves(url, routes)).toBe(true);
  });
});

it('rejects a path with no route behind it', () => {
  // The regression itself, both spellings. Without this the matcher could be
  // trivially true and the suite above would mean nothing.
  expect(resolves('/redemptions/9', routes)).toBe(false);
  expect(resolves('/influencers/7', routes)).toBe(false);
});
