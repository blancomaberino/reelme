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

  it('mounts the five visible tabs in order with map as the initial route (Tonight joined — T-158)', () => {
    render(<MainTabsLayout />);

    // Share moved off the tab bar (href: null → hidden route); Search takes its
    // slot. Tonight (T-158) sits second, beside the map it shares a question
    // with.
    //
    // This is the REACHABILITY assertion for Tonight, and it is worth saying
    // what it does and does not prove. The mock pushes a name only when
    // `options.href !== null` — that is, only for tabs the real bar renders as
    // pressable — so a Tonight route added without a bar entry, or hidden with
    // `href: null`, fails here. What it cannot do is fire the press: the Tabs
    // mock records wiring rather than rendering a bar, so no press target
    // exists in this harness. Being in the pressable set is the strongest claim
    // available without replacing the mock, and it is the one that catches the
    // failure the rule exists for — a screen reachable only by deep link.
    expect(mockRouter.tabNames).toEqual(['map', 'tonight', 'places', 'search', 'profile']);
    expect(mockRouter.initialRouteName).toBe('map');
  });
});
