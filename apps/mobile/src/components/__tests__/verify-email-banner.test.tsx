import { render, screen } from '@testing-library/react-native';

import type { Me } from '@/api/types';
import { VerifyEmailBanner } from '@/components/verify-email-banner';
import { useSessionStore } from '@/stores/session';
import { makeMe } from '@/test/me-fixture';

function user(over: Partial<Me>): Me {
  return makeMe({ name: 'V', username: 'v', email: 'v@example.com', ...over });
}

const BANNER = 'Confirm your email so you don’t lose access.';

afterEach(() => useSessionStore.setState({ user: null, status: 'guest' }));

it('shows for an authed, unverified account', () => {
  useSessionStore.setState({ user: user({ email_verified_at: null }), status: 'authed' });
  render(<VerifyEmailBanner />);
  expect(screen.getByText(BANNER)).toBeOnTheScreen();
});

it('renders nothing once the email is verified', () => {
  useSessionStore.setState({ user: user({ email_verified_at: '2026-07-14T00:00:00Z' }), status: 'authed' });
  render(<VerifyEmailBanner />);
  expect(screen.queryByText(BANNER)).toBeNull();
});

it('renders nothing for a guest', () => {
  useSessionStore.setState({ user: null, status: 'guest' });
  render(<VerifyEmailBanner />);
  expect(screen.queryByText(BANNER)).toBeNull();
});
