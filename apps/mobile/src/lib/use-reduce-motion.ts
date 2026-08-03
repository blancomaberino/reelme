import { useEffect, useState } from 'react';
import { AccessibilityInfo } from 'react-native';

/**
 * The OS "reduce motion" setting (iOS: Settings ▸ Accessibility ▸ Motion;
 * Android: Remove animations), live — it re-reads when the user flips it
 * without leaving the app.
 *
 * Returns `undefined` until the first async read resolves, and that third state
 * is the point: defaulting to `false` would start the animation on the first
 * frame and stop it one tick later, which is precisely the flash of motion the
 * setting exists to prevent. Callers should treat only `false` as permission to
 * animate (`reduced === false`), never `!reduced`.
 */
export function useReduceMotion(): boolean | undefined {
  const [reduced, setReduced] = useState<boolean | undefined>(undefined);

  useEffect(() => {
    let alive = true;
    void AccessibilityInfo.isReduceMotionEnabled().then((enabled) => {
      if (alive) setReduced(enabled);
    });
    const sub = AccessibilityInfo.addEventListener('reduceMotionChanged', setReduced);
    return () => {
      alive = false;
      sub.remove();
    };
  }, []);

  return reduced;
}
