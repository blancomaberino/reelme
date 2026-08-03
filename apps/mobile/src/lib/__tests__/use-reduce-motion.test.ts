import { act, renderHook } from '@testing-library/react-native';
import { AccessibilityInfo } from 'react-native';

import { useReduceMotion } from '@/lib/use-reduce-motion';

/**
 * T-108. The hook exists so a skeleton can hold still for users who asked the
 * OS for less motion — which makes the *unknown* window (before the async read
 * lands) the interesting part, not an implementation detail.
 */

let listeners: ((enabled: boolean) => void)[] = [];
let remove: jest.Mock;

function mockReduceMotion(enabled: boolean | Promise<boolean>) {
  jest
    .spyOn(AccessibilityInfo, 'isReduceMotionEnabled')
    .mockReturnValue(enabled instanceof Promise ? enabled : Promise.resolve(enabled));
}

beforeEach(() => {
  listeners = [];
  remove = jest.fn();
  mockReduceMotion(false);
  jest.spyOn(AccessibilityInfo, 'addEventListener').mockImplementation(((
    event: string,
    handler: (enabled: boolean) => void,
  ) => {
    if (event === 'reduceMotionChanged') listeners.push(handler);
    return { remove };
  }) as unknown as typeof AccessibilityInfo.addEventListener);
});

afterEach(() => {
  jest.restoreAllMocks();
});

it('reports undefined until the OS answers, then the setting', async () => {
  mockReduceMotion(true);

  const { result } = renderHook(() => useReduceMotion());

  // Not `false`: a caller must be able to tell "no reduce motion" apart from
  // "we have not asked yet", or it animates one frame at the wrong people.
  expect(result.current).toBeUndefined();

  await act(async () => {});

  expect(result.current).toBe(true);
});

it.each([true, false])('passes through the OS value %s', async (enabled) => {
  mockReduceMotion(enabled);

  const { result } = renderHook(() => useReduceMotion());
  await act(async () => {});

  expect(result.current).toBe(enabled);
});

it('follows the setting being flipped while the screen is open', async () => {
  const { result } = renderHook(() => useReduceMotion());
  await act(async () => {});
  expect(result.current).toBe(false);

  await act(async () => {
    for (const notify of listeners) notify(true);
  });

  expect(result.current).toBe(true);
});

it('unsubscribes on unmount', async () => {
  const { unmount } = renderHook(() => useReduceMotion());
  await act(async () => {});

  unmount();

  expect(remove).toHaveBeenCalled();
});

it('ignores a read that lands after unmount', async () => {
  // The classic setState-on-unmounted warning: the native read is async and a
  // user can leave the screen before it resolves.
  let answer!: (enabled: boolean) => void;
  mockReduceMotion(new Promise<boolean>((resolve) => (answer = resolve)));
  const error = jest.spyOn(console, 'error').mockImplementation(() => {});

  const { unmount } = renderHook(() => useReduceMotion());
  unmount();

  await act(async () => {
    answer(true);
  });

  expect(error).not.toHaveBeenCalled();
});
