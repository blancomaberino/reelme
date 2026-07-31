import { Ionicons } from '@expo/vector-icons';
import { useEffect, useMemo, useRef } from 'react';
import { AccessibilityInfo, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { type MessageKey, useT } from '@/i18n';
import { useUiStore } from '@/stores/ui';
import { type Palette, useColors } from '@/theme/colors';

/**
 * The one place the app tells you the connection is the problem (T-103).
 *
 * Two conditions share it, because to the user they are the same sentence —
 * "this isn't going to load right now, and it isn't your fault":
 *   - `offline`     — NetInfo says there is no reachable network.
 *   - `rateLimited` — the API returned a 429 (flag set since T-016, never shown
 *                     until now; the response interceptor clears it on the next
 *                     successful request).
 * Offline wins when both are set: a throttle you can't even reach is moot.
 *
 * Visually it is a market tag — a warm paper pill floating over the canvas
 * rather than a full-width alarm bar, so it reads as an aside and never fights
 * the food photography.
 *
 * It floats rather than taking layout: the map is fullscreen with no header to
 * push down, and reflowing every screen on a connectivity flip would re-layout
 * the MapView. It sits at the BOTTOM, clear of the tab bar — the top of the map
 * is already occupied by the filter bar and the search/add buttons, and every
 * other screen puts its title there. `pointerEvents="none"` throughout: this is
 * a statement, not a control, and it must never eat a tap meant for a pin
 * underneath it.
 */
export function ConnectionBanner() {
  const offline = useUiStore((s) => s.offline);
  const rateLimited = useUiStore((s) => s.rateLimited);
  const state = offline ? 'offline' : rateLimited ? 'rateLimited' : null;

  useConnectionAnnouncements(state);

  if (!state) return null;
  return <Banner state={state} />;
}

type BannerState = 'offline' | 'rateLimited';

const COPY: Record<BannerState, { message: MessageKey; icon: 'cloud-offline' | 'hourglass' }> = {
  offline: { message: 'connection.offline', icon: 'cloud-offline' },
  rateLimited: { message: 'connection.rateLimited', icon: 'hourglass' },
};

/**
 * Split out so the hooks below only run when something is actually shown —
 * `ConnectionBanner` renders on every connectivity flip for the whole app, and
 * building a themed stylesheet for a banner that isn't on screen is waste.
 */
function Banner({ state }: { state: BannerState }) {
  const c = useColors();
  const t = useT();
  const insets = useSafeAreaInsets();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { message, icon } = COPY[state];

  return (
    <View style={[styles.layer, { paddingBottom: insets.bottom + TAB_BAR_CLEARANCE }]} pointerEvents="none">
      <View
        style={styles.tag}
        accessibilityLiveRegion="polite"
        accessibilityRole="alert"
        testID={`connection-banner-${state}`}
      >
        <Ionicons name={icon} size={15} color={c.gold} />
        <Text style={styles.label} numberOfLines={2}>
          {t(message)}
        </Text>
      </View>
    </View>
  );
}

/**
 * Speak the connection change on both platforms. `accessibilityLiveRegion`
 * alone covers Android; VoiceOver does not re-read a view that merely appeared
 * mid-screen, and losing the network is exactly the moment a screen-reader user
 * needs told — every subsequent tap is about to do nothing.
 *
 * Reconnection is announced too (`connection.restored`), but only after having
 * been offline: a silent recovery leaves the user still believing they're cut
 * off. The first render is never announced — landing on a screen already reads
 * the banner out.
 */
function useConnectionAnnouncements(state: BannerState | null): void {
  const t = useT();
  const previous = useRef<BannerState | null | undefined>(undefined);

  useEffect(() => {
    const first = previous.current === undefined;
    if (previous.current === state) return;
    const wasOffline = previous.current === 'offline';
    previous.current = state;
    if (first) return;
    if (state) {
      AccessibilityInfo.announceForAccessibility(t(COPY[state].message));
    } else if (wasOffline) {
      AccessibilityInfo.announceForAccessibility(t('connection.restored'));
    }
  }, [state, t]);
}

/**
 * Height of a tab bar plus a little air, so the pill clears it on the four
 * tabbed screens. On a pushed screen (place detail, settings) there is no tab
 * bar and the pill simply floats a snackbar's distance off the bottom — which
 * is where a transient status belongs there too.
 */
const TAB_BAR_CLEARANCE = 60;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    // Absolute so it can sit over a fullscreen map without reserving layout.
    layer: {
      position: 'absolute',
      bottom: 0,
      left: 0,
      right: 0,
      alignItems: 'center',
      zIndex: 20,
    },
    tag: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 8,
      maxWidth: '92%',
      paddingVertical: 8,
      paddingHorizontal: 14,
      borderRadius: 999,
      backgroundColor: c.goldSoft,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.gold,
      // Lifted off the canvas — it is pinned ON the app, not part of it.
      shadowColor: '#000',
      shadowOpacity: 0.12,
      shadowRadius: 8,
      shadowOffset: { width: 0, height: 2 },
      elevation: 3,
    },
    label: { flexShrink: 1, fontSize: 13, fontWeight: '600', color: c.gold },
  });
