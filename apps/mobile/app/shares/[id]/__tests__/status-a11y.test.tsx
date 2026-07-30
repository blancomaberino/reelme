import { render, screen } from '@testing-library/react-native';
import { AccessibilityInfo } from 'react-native';

import type { ShareDetail } from '@/api/shares';
import { shareDetail } from '@/test/share-fixtures';

import StatusScreen from '../status';

import { mockRouter } from '../../../../jest.setup';

// T-101 — screen-reader behaviour of the share status screen.
//
// The screen polls and re-renders IN PLACE: focus never moves, and VoiceOver
// does not re-read a view that merely re-rendered. So without an explicit
// announcement a blind user watching a share process gets no signal that
// anything happened — the screen simply goes quiet until they swipe around
// looking for a change.
//
// Lives apart from status.test.tsx because it drives `useShareStatus` directly.
// The real hook holds a 2s `refetchInterval` while the share is non-terminal,
// and pumping transitions through that live query deadlocks `act()`. Mocking
// the hook isolates exactly what is under test — what the screen DOES when the
// status it is handed changes — with no clock involved.

// `mock`-prefixed so jest allows it inside the hoisted factory.
const mockCurrent: { data: ShareDetail | undefined } = { data: undefined };
jest.mock('@/api/hooks/useShareStatus', () => ({
  useShareStatus: () => ({ data: mockCurrent.data, isLoading: false, isError: false }),
}));
jest.mock('@/api/hooks/useRetryShare', () => ({
  useRetryShare: () => ({ mutate: jest.fn(), isPending: false }),
}));

const announce = jest.spyOn(AccessibilityInfo, 'announceForAccessibility');

const published = () =>
  shareDetail({
    status: 'published',
    place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 },
    places: [{ id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 }],
  });

beforeEach(() => {
  announce.mockClear();
  mockRouter.params = { id: '1' };
  mockCurrent.data = undefined;
});

it('stays silent on the status it arrives showing', () => {
  // VoiceOver already reads the view it just landed on. Announcing the same
  // thing on top of that talks over the user rather than informing them.
  mockCurrent.data = shareDetail({ status: 'analyzing' });
  render(<StatusScreen />);

  expect(screen.getByTestId('step-analyzing-active')).toBeOnTheScreen();
  expect(announce).not.toHaveBeenCalled();
});

it('announces each pipeline transition', () => {
  mockCurrent.data = shareDetail({ status: 'fetching' });
  const { rerender } = render(<StatusScreen />);
  expect(announce).not.toHaveBeenCalled();

  mockCurrent.data = shareDetail({ status: 'analyzing' });
  rerender(<StatusScreen />);
  expect(announce).toHaveBeenCalledWith('Share status: Finding the place');

  mockCurrent.data = published();
  rerender(<StatusScreen />);
  expect(announce).toHaveBeenLastCalledWith('Share status: Published');
  expect(announce).toHaveBeenCalledTimes(2);
});

it('announces a failure, not just the happy path', () => {
  mockCurrent.data = shareDetail({ status: 'analyzing' });
  const { rerender } = render(<StatusScreen />);

  mockCurrent.data = shareDetail({
    status: 'failed',
    failure: { code: 'quota_exhausted', step: null, message: 'x', manual_fallback: false },
  });
  rerender(<StatusScreen />);

  expect(announce).toHaveBeenCalledWith('Share status: Something went wrong');
});

it('does not repeat itself while the status holds', () => {
  // The screen re-renders on every 2s poll. A live region that re-fired each
  // time would make the screen unusable with a screen reader on.
  mockCurrent.data = shareDetail({ status: 'fetching' });
  const { rerender } = render(<StatusScreen />);

  mockCurrent.data = shareDetail({ status: 'analyzing' });
  rerender(<StatusScreen />);
  expect(announce).toHaveBeenCalledTimes(1);

  // Same status, fresh object each time — as the real poll produces.
  mockCurrent.data = shareDetail({ status: 'analyzing' });
  rerender(<StatusScreen />);
  mockCurrent.data = shareDetail({ status: 'analyzing' });
  rerender(<StatusScreen />);

  expect(announce).toHaveBeenCalledTimes(1);
});

describe('live region (Android)', () => {
  // announceForAccessibility covers both platforms; the live region is what
  // makes TalkBack re-read the outcome card itself.
  it.each([
    ['published', published],
    [
      'failed',
      () =>
        shareDetail({
          status: 'failed',
          failure: { code: 'quota_exhausted', step: null, message: 'x', manual_fallback: false },
        }),
    ],
  ])('marks the %s outcome card polite', (_label, build) => {
    mockCurrent.data = build();
    render(<StatusScreen />);

    expect(screen.UNSAFE_getAllByProps({ accessibilityLiveRegion: 'polite' }).length).toBeGreaterThan(0);
  });
});
