import * as Brightness from 'expo-brightness';
import { useEffect } from 'react';

/**
 * Raise the screen while a QR is visible, restore it after (T-047, screen #18).
 *
 * A dim phone in a dim restaurant is the difference between a scan that works
 * and staff giving up and typing the code by hand. The override is RELINQUISHED
 * on unmount rather than overwritten with a captured value — silently leaving
 * someone's screen at full is a battery complaint they will never connect to
 * this app, and writing back a number we read earlier would also clobber any
 * auto-brightness change made while the code was up.
 *
 * Every call is guarded. Brightness needs a permission on Android and is a
 * no-op in some environments; failing to brighten a QR is a small annoyance,
 * while throwing inside an effect on the screen a customer is trying to pay
 * with is not.
 */
export function useScreenBrightness(active: boolean): void {
  useEffect(() => {
    if (!active) return;

    let cancelled = false;

    void (async () => {
      try {
        if (cancelled) return;
        await Brightness.setBrightnessAsync(1);
      } catch {
        // Not worth surfacing — the code is still perfectly readable.
      }
    })();

    return () => {
      cancelled = true;

      void (async () => {
        try {
          await Brightness.restoreSystemBrightnessAsync();
        } catch {
          // Same: a failure to restore must not crash a screen teardown.
        }
      })();
    };
  }, [active]);
}
