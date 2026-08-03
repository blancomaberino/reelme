import { act, render, screen } from '@testing-library/react-native';
import { AccessibilityInfo, Animated } from 'react-native';

import { Skeleton, SkeletonGroup } from '@/components/skeleton';
import { schemes } from '@/theme/colors';
import { radius } from '@/theme/tokens';

/**
 * T-108. The two things that are easy to get wrong here and invisible in a
 * screenshot: the loop must not start when the OS asks for less motion, and N
 * blocks must share ONE clock — independent loops drift apart within seconds
 * and the placeholder starts reading as a bug.
 */

/** `reduceMotionChanged` subscribers, so a test can flip the setting live. */
let listeners: ((enabled: boolean) => void)[] = [];
let loopSpy: jest.SpyInstance;
let timingSpy: jest.SpyInstance;

/**
 * The Animated.Value driving the blocks. `Animated.View` pushes updates
 * straight to the native props, so the rendered style prop stays frozen at its
 * mount value and cannot be read to observe motion — the value itself can.
 */
function clock(): Animated.Value {
  return timingSpy.mock.calls[0][0] as Animated.Value;
}

function mockReduceMotion(enabled: boolean) {
  jest.spyOn(AccessibilityInfo, 'isReduceMotionEnabled').mockResolvedValue(enabled);
}

beforeEach(() => {
  listeners = [];
  mockReduceMotion(false);
  jest.spyOn(AccessibilityInfo, 'addEventListener').mockImplementation(((
    event: string,
    handler: (enabled: boolean) => void,
  ) => {
    if (event === 'reduceMotionChanged') listeners.push(handler);
    return { remove: jest.fn() };
  }) as unknown as typeof AccessibilityInfo.addEventListener);
  loopSpy = jest.spyOn(Animated, 'loop');
  timingSpy = jest.spyOn(Animated, 'timing');
});

afterEach(() => {
  jest.restoreAllMocks();
});

/** Mount and let the async reduce-motion read settle. */
async function mount(ui: React.ReactElement) {
  render(ui);
  await act(async () => {});
}

/**
 * Blocks are deliberately hidden from the accessibility tree, which is also how
 * RNTL decides what a query can see — so reaching one takes the opt-in. That is
 * the assertion in "hides the individual blocks", not an inconvenience.
 */
function block(testID: string) {
  return screen.getByTestId(testID, { includeHiddenElements: true });
}

function styleOf(testID: string) {
  return Object.assign({}, ...[block(testID).props.style].flat(Infinity).filter(Boolean));
}

it('draws blocks in the palette skeleton fill at the geometry given', async () => {
  await mount(
    <SkeletonGroup testID="group">
      <Skeleton testID="bar" height={17} width="66%" />
    </SkeletonGroup>,
  );

  const style = styleOf('bar');
  expect(style.backgroundColor).toBe(schemes.light.skeleton);
  expect(style.height).toBe(17);
  expect(style.width).toBe('66%');
});

it('leaves width to layout when none is given, so a flex row can share it', async () => {
  // A default of '100%' would set a flex-basis RN never shrinks (flexShrink
  // defaults to 0) and two blocks in one row would overflow it.
  await mount(
    <SkeletonGroup>
      <Skeleton testID="fill" height={48} shape="block" />
    </SkeletonGroup>,
  );

  expect(styleOf('fill').width).toBeUndefined();
});

it('rounds a bar into a pill and a block to the tile radius', async () => {
  await mount(
    <SkeletonGroup>
      <Skeleton testID="bar" height={15} />
      <Skeleton testID="tile" height={190} shape="block" />
      <Skeleton testID="dot" height={18} shape="circle" />
    </SkeletonGroup>,
  );

  expect(styleOf('bar').borderRadius).toBe(radius.pill);
  expect(styleOf('tile').borderRadius).toBe(radius.lg);
  // A circle is sized and rounded off its height, not the pill constant — which
  // RN would clamp to a stadium the moment width exceeded height.
  expect(styleOf('dot').width).toBe(18);
  expect(styleOf('dot').borderRadius).toBe(18 / 2);
});

it('announces loading once and hides the individual blocks from a screen reader', async () => {
  await mount(
    <SkeletonGroup testID="group">
      <Skeleton testID="a" height={15} />
      <Skeleton testID="b" height={15} />
    </SkeletonGroup>,
  );

  const group = screen.getByTestId('group');
  expect(group.props.accessibilityRole).toBe('progressbar');
  expect(group.props.accessibilityLabel).toBe('Loading');
  // One announcement, not one per block.
  expect(screen.getAllByRole('progressbar')).toHaveLength(1);
  for (const id of ['a', 'b']) {
    // Absent from the accessibility tree entirely — VoiceOver would otherwise
    // walk a dozen unlabelled elements before reaching anything meaningful.
    expect(screen.queryByTestId(id)).toBeNull();
    expect(block(id).props.accessibilityElementsHidden).toBe(true);
    expect(block(id).props.importantForAccessibility).toBe('no-hide-descendants');
  }
});

it('takes a caller-supplied label over the default', async () => {
  await mount(
    <SkeletonGroup testID="group" label="Loading places">
      <Skeleton height={15} />
    </SkeletonGroup>,
  );

  expect(screen.getByTestId('group').props.accessibilityLabel).toBe('Loading places');
});

describe('motion', () => {
  it('breathes once for the whole group, not once per block', async () => {
    await mount(
      <SkeletonGroup>
        <Skeleton testID="a" height={15} />
        <Skeleton testID="b" height={15} />
        <Skeleton testID="c" height={15} />
      </SkeletonGroup>,
    );

    // One loop for three blocks is the proof: a block that drove itself would
    // put the count at three, and they would drift out of phase on device.
    expect(loopSpy).toHaveBeenCalledTimes(1);
    // Exactly one dim/undim pair built for that loop, over a single value.
    expect(timingSpy).toHaveBeenCalledTimes(2);
    expect(timingSpy.mock.calls[1][0]).toBe(clock());
  });

  it('does not animate at all when reduce motion is on', async () => {
    mockReduceMotion(true);

    await mount(
      <SkeletonGroup>
        <Skeleton testID="a" height={15} />
      </SkeletonGroup>,
    );

    expect(loopSpy).not.toHaveBeenCalled();
    expect(timingSpy).not.toHaveBeenCalled();
    expect(styleOf('a').opacity).toBe(1);
  });

  it('does not animate before the setting has been read', async () => {
    // The read is async. Defaulting to "motion is fine" would flash one frame of
    // animation at exactly the users who asked for none, so nothing starts until
    // the answer is in. Note the deliberately un-awaited render.
    render(
      <SkeletonGroup>
        <Skeleton testID="a" height={15} />
      </SkeletonGroup>,
    );

    expect(loopSpy).not.toHaveBeenCalled();

    // Let the pending read land inside act(), so it doesn't warn from the next test.
    await act(async () => {});
  });

  it('stops and settles at full opacity when reduce motion is switched on live', async () => {
    await mount(
      <SkeletonGroup>
        <Skeleton testID="a" height={15} />
      </SkeletonGroup>,
    );
    const loop = loopSpy.mock.results[0].value as Animated.CompositeAnimation;
    const stop = jest.spyOn(loop, 'stop');
    // Observed through the public listener API rather than `__getValue`, which
    // is private and not in the type definitions.
    const seen: number[] = [];
    clock().addListener(({ value }) => seen.push(value));
    // Mid-breath when the setting flips.
    act(() => clock().setValue(0.45));

    await act(async () => {
      for (const notify of listeners) notify(true);
    });

    expect(stop).toHaveBeenCalled();
    // Never leave a stopped skeleton frozen half-dimmed.
    expect(seen).toEqual([0.45, 1]);
    // And it does not quietly restart.
    expect(loopSpy).toHaveBeenCalledTimes(1);
  });

  it('animates a lone block that has no group around it', async () => {
    await mount(<Skeleton testID="solo" height={15} />);

    expect(loopSpy).toHaveBeenCalledTimes(1);
  });
});
