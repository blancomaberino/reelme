import { act, render, screen } from '@testing-library/react-native';
import { AccessibilityInfo } from 'react-native';

import { ConnectionBanner } from '@/components/connection-banner';
import { useUiStore } from '@/stores/ui';

const announce = jest.spyOn(AccessibilityInfo, 'announceForAccessibility');

beforeEach(() => {
  useUiStore.setState({ offline: false, rateLimited: false });
  announce.mockClear();
});

describe('ConnectionBanner', () => {
  it('shows nothing while the connection is healthy', () => {
    render(<ConnectionBanner />);

    expect(screen.queryByTestId('connection-banner-offline')).toBeNull();
    expect(screen.queryByTestId('connection-banner-rateLimited')).toBeNull();
  });

  it('appears when the connection drops and clears on reconnect', () => {
    render(<ConnectionBanner />);

    act(() => useUiStore.setState({ offline: true }));
    expect(screen.getByTestId('connection-banner-offline')).toBeTruthy();
    expect(screen.getByText('You’re offline — showing your saved places')).toBeTruthy();

    act(() => useUiStore.setState({ offline: false }));
    expect(screen.queryByTestId('connection-banner-offline')).toBeNull();
  });

  it('surfaces a 429 throttle, the flag the client has set since T-016', () => {
    useUiStore.setState({ rateLimited: true });
    render(<ConnectionBanner />);

    expect(screen.getByTestId('connection-banner-rateLimited')).toBeTruthy();
  });

  it('prefers "offline" when both flags are set — an unreachable throttle is moot', () => {
    useUiStore.setState({ offline: true, rateLimited: true });
    render(<ConnectionBanner />);

    expect(screen.getByTestId('connection-banner-offline')).toBeTruthy();
    expect(screen.queryByTestId('connection-banner-rateLimited')).toBeNull();
  });

  it('speaks the change, in both directions, but never on first render', () => {
    useUiStore.setState({ offline: true });
    render(<ConnectionBanner />);
    // Arriving on a screen already reads the banner out; announcing it again
    // would talk over VoiceOver.
    expect(announce).not.toHaveBeenCalled();

    act(() => useUiStore.setState({ offline: false }));
    expect(announce).toHaveBeenCalledWith('Back online');

    announce.mockClear();
    act(() => useUiStore.setState({ offline: true }));
    expect(announce).toHaveBeenCalledWith('You’re offline — showing your saved places');
  });

  it('is a live region, so Android reads it without a focus change', () => {
    useUiStore.setState({ offline: true });
    render(<ConnectionBanner />);

    expect(screen.getByTestId('connection-banner-offline').props.accessibilityLiveRegion).toBe('polite');
  });

  it('never swallows a tap meant for the map underneath it', () => {
    useUiStore.setState({ offline: true });
    render(<ConnectionBanner />);

    expect(screen.UNSAFE_getAllByProps({ pointerEvents: 'none' }).length).toBeGreaterThan(0);
  });
});
