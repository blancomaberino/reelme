import { act, renderHook } from '@testing-library/react-native';

import { useDebounced } from '../use-debounced';

beforeEach(() => jest.useFakeTimers());
afterEach(() => jest.useRealTimers());

it('delays the value by the given interval', () => {
  const { result, rerender } = renderHook<string, { v: string }>(({ v }) => useDebounced(v, 300), {
    initialProps: { v: 'a' },
  });
  expect(result.current).toBe('a');

  rerender({ v: 'ab' });
  rerender({ v: 'abc' });
  // Not yet elapsed — still the initial value.
  expect(result.current).toBe('a');

  act(() => jest.advanceTimersByTime(300));
  // Only the latest value lands (intermediate 'ab' is coalesced away).
  expect(result.current).toBe('abc');
});
