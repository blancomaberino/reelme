import { render } from '@testing-library/react-native';

// expo-router is mocked once, globally, in jest.setup.ts (a setupFilesAfterEnv
// mock overrides any inline jest.mock here), and it captures the redirect target
// and tab wiring on the shared mockRouter.
import { mockRouter } from '../jest.setup';
import Index from '../app/index';
import MainTabsLayout from '../app/(main)/_layout';
import { useSessionStore } from '@/stores/session';

describe('navigation wiring', () => {
  beforeEach(() => {
    mockRouter.redirectHref = null;
    mockRouter.initialRouteName = null;
    mockRouter.tabNames = [];
  });

  // The entry route (app/index.tsx) is auth-gated: it redirects based on the
  // resolved session status and renders nothing while auth is still resolving.
  it('redirects an authenticated user into the map tab', () => {
    useSessionStore.setState({ user: null, status: 'authed' });
    render(<Index />);

    expect(mockRouter.redirectHref).toBe('/(main)/map');
  });

  it('redirects a guest to the welcome screen', () => {
    useSessionStore.setState({ user: null, status: 'guest' });
    render(<Index />);

    expect(mockRouter.redirectHref).toBe('/(auth)/welcome');
  });

  it('renders nothing (no premature redirect) while auth is loading', () => {
    useSessionStore.setState({ user: null, status: 'loading' });
    render(<Index />);

    expect(mockRouter.redirectHref).toBeNull();
  });

  it('mounts the four tabs in order with map as the initial route', () => {
    render(<MainTabsLayout />);

    expect(mockRouter.tabNames).toEqual(['map', 'feed', 'share', 'profile']);
    expect(mockRouter.initialRouteName).toBe('map');
  });
});
