import { fireEvent, render, screen } from '@testing-library/react-native';
import { Text } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { ErrorBoundary } from '../error-boundary';
import { reportError } from '@/lib/crash-reporting';

jest.mock('@/lib/crash-reporting', () => ({
  reportError: jest.fn(),
  initCrashReporting: jest.fn(),
}));

// A child that throws until the transient condition clears — models a render
// error (bad prop / null deref) that a retry can recover from.
let shouldThrow = true;
function Boom() {
  if (shouldThrow) {
    throw new Error('kaboom');
  }

  return <Text>Recovered content</Text>;
}

function renderBoundary() {
  return render(
    <SafeAreaProvider initialMetrics={{ frame: { x: 0, y: 0, width: 0, height: 0 }, insets: { top: 0, left: 0, right: 0, bottom: 0 } }}>
      <ErrorBoundary>
        <Boom />
      </ErrorBoundary>
    </SafeAreaProvider>,
  );
}

let consoleError: jest.SpyInstance;

beforeEach(() => {
  shouldThrow = true;
  (reportError as jest.Mock).mockClear();
  // React logs caught boundary errors to console.error — silence the noise.
  consoleError = jest.spyOn(console, 'error').mockImplementation(() => {});
});

afterEach(() => consoleError.mockRestore());

it('renders the branded fallback instead of a blank screen and reports once', () => {
  renderBoundary();

  // Fallback is shown (not the blank/unmounted tree), and the child is gone.
  expect(screen.getByText('Something went wrong')).toBeOnTheScreen();
  expect(screen.getByLabelText('Try again')).toBeOnTheScreen();
  expect(screen.queryByText('Recovered content')).toBeNull();

  // Reported exactly once (componentDidCatch fires once per error — no loop).
  expect(reportError).toHaveBeenCalledTimes(1);
  expect((reportError as jest.Mock).mock.calls[0][0]).toBeInstanceOf(Error);
});

it('recovers to the children when the fallback is reset', () => {
  renderBoundary();
  expect(screen.getByText('Something went wrong')).toBeOnTheScreen();

  // The transient condition cleared; pressing "try again" re-mounts the subtree.
  shouldThrow = false;
  fireEvent.press(screen.getByLabelText('Try again'));

  expect(screen.getByText('Recovered content')).toBeOnTheScreen();
  expect(screen.queryByText('Something went wrong')).toBeNull();
});
