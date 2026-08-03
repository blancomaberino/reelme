import { render } from '@testing-library/react-native';

import MainTabsLayout from '../_layout';
import { useSessionStore } from '@/stores/session';
import { mockRouter } from '../../../jest.setup';

/**
 * The Wallet tab is conditional (T-046, 05 §tab rules).
 *
 * It appears only for accounts that can HOLD money. Note what this is and is
 * not: the API 403s a plain diner regardless, so hiding the tab is a UX
 * decision — not showing someone a wallet they can never have — rather than the
 * security boundary. The route stays addressable, which is why `href: null` is
 * the right tool.
 */
beforeEach(() => {
  mockRouter.tabNames = [];
});

afterEach(() => {
  useSessionStore.setState({ user: null, status: 'guest' });
});

function renderTabsFor(user: Record<string, unknown> | null) {
  useSessionStore.setState({ user: user as never, status: user ? 'authed' : 'guest' });
  render(<MainTabsLayout />);
}

it('hides the wallet from a plain diner', () => {
  renderTabsFor({ id: '1', is_influencer: false, is_restaurant_owner: false });

  expect(mockRouter.tabNames).not.toContain('wallet');
  // The other tabs are unaffected.
  expect(mockRouter.tabNames).toContain('map');
});

it.each([
  ['an influencer', { is_influencer: true, is_restaurant_owner: false }],
  ['a restaurant operator', { is_influencer: false, is_restaurant_owner: true }],
])('shows it to %s', (_label, flags) => {
  renderTabsFor({ id: '1', ...flags });

  expect(mockRouter.tabNames).toContain('wallet');
});

it('hides it from a guest with no session', () => {
  renderTabsFor(null);

  expect(mockRouter.tabNames).not.toContain('wallet');
});
