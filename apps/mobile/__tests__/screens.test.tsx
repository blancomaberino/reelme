import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react-native';
import type { ReactNode } from 'react';

import LoginScreen from '../app/(auth)/login';
import RegisterScreen from '../app/(auth)/register';
import WelcomeScreen from '../app/(auth)/welcome';
import ProfileScreen from '../app/(main)/profile';
import { useSessionStore } from '@/stores/session';

// Drive the real theme hook so both palette branches are exercised.
const mockColorScheme = jest.fn<'light' | 'dark' | null, []>(() => 'light');
jest.mock('react-native/Libraries/Utilities/useColorScheme', () => ({
  __esModule: true,
  default: () => mockColorScheme(),
}));

let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  // gcTime: 0 so no cache-GC timer keeps the jest worker alive after the test.
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: false } } });
  mockColorScheme.mockReturnValue('light');
  useSessionStore.setState({ user: null, status: 'guest' });
});

afterEach(() => qc.clear());

describe('auth screens render across color schemes', () => {
  it('welcome shows the brand and both entry CTAs', () => {
    render(<WelcomeScreen />, { wrapper: Providers });
    expect(screen.getByText('Reelmap')).toBeOnTheScreen();
    expect(screen.getByText('Create account')).toBeOnTheScreen();
    expect(screen.getByText('Log in')).toBeOnTheScreen();
  });

  it('login exposes its fields and heading in dark mode too', () => {
    mockColorScheme.mockReturnValue('dark');
    render(<LoginScreen />, { wrapper: Providers });
    expect(screen.getByText('Welcome back')).toBeOnTheScreen();
    // Labels double as the inputs' accessibility labels.
    expect(screen.getByLabelText('Email')).toBeOnTheScreen();
    expect(screen.getByLabelText('Password')).toBeOnTheScreen();
  });

  it('register renders every signup field', () => {
    render(<RegisterScreen />, { wrapper: Providers });
    for (const label of ['Name', 'Username', 'Email', 'Password']) {
      expect(screen.getByLabelText(label)).toBeOnTheScreen();
    }
  });

  it('profile shows the signed-in user and a logout control', () => {
    useSessionStore.setState({
      user: {
        id: '1',
        name: 'Maya',
        username: 'maya',
        email: 'maya@example.com',
        avatar_path: null,
        bio: null,
        is_influencer: false,
        is_restaurant_owner: false,
        is_admin: false,
        is_public: true,
        preferred_analysis_model: null,
        stripe_connect_onboarded: false,
        email_verified_at: null,
        created_at: null,
      },
      status: 'authed',
    });
    render(<ProfileScreen />, { wrapper: Providers });
    expect(screen.getByText('Maya')).toBeOnTheScreen();
    expect(screen.getByText('@maya')).toBeOnTheScreen();
    expect(screen.getByText('Log out')).toBeOnTheScreen();
  });
});

describe('text field focus state', () => {
  it('reflects focus/blur without losing the input (guards the makeStyles refactor)', () => {
    render(<LoginScreen />, { wrapper: Providers });
    const email = screen.getByLabelText('Email');
    fireEvent(email, 'focus');
    fireEvent.changeText(email, 'maya@example.com');
    fireEvent(email, 'blur');
    expect(screen.getByDisplayValue('maya@example.com')).toBeOnTheScreen();
  });
});
