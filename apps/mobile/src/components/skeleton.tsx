import { createContext, useContext, useEffect, useState } from 'react';
import { Animated, Easing, StyleSheet, View, type DimensionValue, type StyleProp, type ViewStyle } from 'react-native';

import { useT } from '@/i18n';
import { useReduceMotion } from '@/lib/use-reduce-motion';
import { useColors } from '@/theme/colors';
import { radius } from '@/theme/tokens';

/**
 * Skeleton placeholders (T-108) — the shape of the content, drawn in kraft
 * paper, instead of a spinner that says only "something is happening".
 *
 * Two deliberate choices:
 *
 * - **It breathes, it doesn't shimmer.** The usual diagonal sheen needs a
 *   gradient (a native dependency, so a dev-client rebuild) and reads as
 *   chrome sliding over glass — wrong for a palette built on warm paper. A slow
 *   opacity breath is native-driver cheap and looks like paper catching light.
 * - **One clock for the whole screen.** {@link SkeletonGroup} owns the animation
 *   and hands it down; blocks that each ran their own loop would drift out of
 *   phase within seconds and read as a glitch rather than a placeholder.
 */

/** Resting → dimmed. Deep enough to be unmistakably alive, shallow enough not to strobe. */
const DIM = 0.45;
/** Half-cycle. ~1.8s per breath — slower than a spinner on purpose. */
const HALF_CYCLE_MS = 900;

/** The group's shared clock. `null` when a block is used on its own. */
const PulseContext = createContext<Animated.Value | null>(null);

function usePulseValue(enabled: boolean): Animated.Value {
  const reduced = useReduceMotion();
  // Lazy `useState` rather than a ref: one Animated.Value per mount, created
  // once, and readable during render (a ref is not).
  const [value] = useState(() => new Animated.Value(1));

  useEffect(() => {
    // `!== false` covers both "reduce motion is on" and "we don't know yet":
    // neither is permission to move. See use-reduce-motion.ts.
    if (!enabled || reduced !== false) return;

    const breathe = (toValue: number) =>
      Animated.timing(value, {
        toValue,
        duration: HALF_CYCLE_MS,
        easing: Easing.inOut(Easing.quad),
        useNativeDriver: true,
      });

    const loop = Animated.loop(Animated.sequence([breathe(DIM), breathe(1)]));
    loop.start();
    return () => {
      loop.stop();
      // Back to full so a stopped skeleton never freezes mid-dim — including
      // when reduce-motion is switched on while this screen is open.
      value.setValue(1);
    };
  }, [enabled, reduced, value]);

  return value;
}

/** The shared clock if we're inside a group, else this block's own. */
function usePulse(): Animated.Value {
  const shared = useContext(PulseContext);
  const own = usePulseValue(shared === null);
  return shared ?? own;
}

type SkeletonProps = {
  /**
   * Omit to let layout decide — a block stretches to its column, and `flex: 1`
   * via `style` works. Setting a default `'100%'` here would instead pin a
   * flex-basis that RN never shrinks (flexShrink defaults to 0), overflowing
   * any row with more than one block in it.
   */
  width?: DimensionValue;
  height: number;
  /** Text-shaped bars read best fully rounded; blocks take the tile radius. */
  shape?: 'bar' | 'block' | 'circle';
  style?: StyleProp<ViewStyle>;
  testID?: string;
};

/**
 * One placeholder block. Give it the geometry of the real element it stands in
 * for — matching heights is what keeps content from jumping when it lands.
 */
export function Skeleton({ width, height, shape = 'bar', style, testID }: SkeletonProps) {
  const c = useColors();
  const pulse = usePulse();

  return (
    <Animated.View
      testID={testID}
      // Decorative: the group above already announces "loading" once. Left
      // visible to accessibility as individual blocks, VoiceOver would read a
      // dozen empty elements.
      accessibilityElementsHidden
      importantForAccessibility="no-hide-descendants"
      style={[
        shape === 'block' ? shapes.block : shapes.bar,
        { backgroundColor: c.skeleton, height, opacity: pulse },
        // A circle is defined by its height — width follows, and the radius has
        // to be half of it rather than the pill constant, which RN would clamp
        // to a stadium if width ever exceeded height.
        shape === 'circle' ? { width: height, borderRadius: height / 2 } : width !== undefined ? { width } : null,
        style,
      ]}
    />
  );
}

type GroupProps = {
  children: React.ReactNode;
  /** Overrides the default "Loading" announcement. */
  label?: string;
  style?: StyleProp<ViewStyle>;
  testID?: string;
};

/**
 * Wraps a screen's placeholders: drives one shared breath for every block
 * inside, and is the single thing a screen reader announces while they show.
 */
export function SkeletonGroup({ children, label, style, testID }: GroupProps) {
  const t = useT();
  const pulse = usePulseValue(true);

  return (
    <PulseContext.Provider value={pulse}>
      <View
        testID={testID}
        // `accessible` is what makes the group a single focusable element rather
        // than a container the reader walks into; without it the role is inert
        // and the blocks below are all a screen reader would find.
        accessible
        accessibilityRole="progressbar"
        accessibilityLabel={label ?? t('common.loading')}
        style={style}
      >
        {children}
      </View>
    </PulseContext.Provider>
  );
}

// Only the fill is palette-dependent, so the radii are built once for the whole
// app rather than per block through a `makeStyles(c)` — a screen mounts a dozen
// of these, and the usual per-component factory would rebuild each one's
// StyleSheet on every scheme change for two static values.
const shapes = StyleSheet.create({
  bar: { borderRadius: radius.pill },
  block: { borderRadius: radius.lg },
});
