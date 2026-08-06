import { Ionicons } from '@expo/vector-icons';
import { FlashList } from '@shopify/flash-list';
import { Stack, router } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Animated, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { RectButton, Swipeable } from 'react-native-gesture-handler';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useMarkRead, useNotifications } from '@/api/hooks/useNotifications';
import type { NotificationRow } from '@/api/notifications';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
import { elapsedSince } from '@/lib/format';
import { useReduceMotion } from '@/lib/use-reduce-motion';
import { notificationCopy } from '@/notifications/copy';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * The notification center (T-040, 05 screen #23).
 *
 * A push is an interruption; this is the record. Everything the pipeline and
 * the social graph emit lands here with the same `{type, url, title, body}`
 * payload, so a push the user dismissed on the lock screen is still findable.
 *
 * Rows route through `data.url` — the identical path the push tap handler uses
 * (05 §5.2). One routing contract, no per-type switch, which is what keeps a
 * type M4 adds working here before this screen knows about it.
 *
 * Copy is resolved by {@see notificationCopy} from `type` + the params on the
 * row, NOT read off the row's stored `title`/`body`. Those are written once by
 * a worker in whatever language the account was set to that day and then frozen;
 * rendering them meant the list mixed languages and printed the raw machine
 * string for any row that predated them.
 */

/** Icon per known type; anything else falls back to a neutral bell. */
const ICONS: Record<string, keyof typeof Ionicons.glyphMap> = {
  'share.published': 'checkmark-circle',
  'share.review_needed': 'help-circle',
  'share.failed': 'alert-circle',
  'social.follow': 'person-add',
  'influencer.claim_rejected': 'close-circle',
  'redemption.verified': 'ticket',
  'wallet.payout': 'cash',
};

/** Colour per known type — muted by default so only outcomes carry hue. */
function tintFor(c: Palette, type: string): string {
  if (type === 'share.published' || type === 'redemption.verified') return c.green;
  if (type === 'share.failed' || type === 'influencer.claim_rejected') return c.danger;
  if (type === 'share.review_needed') return c.gold;
  return c.secondary;
}

type Row = { kind: 'header'; label: string } | { kind: 'row'; item: NotificationRow };

export default function NotificationsScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const { data, isLoading, isError, refetch, isRefetching, fetchNextPage, hasNextPage, isFetchingNextPage } =
    useNotifications();
  const markRead = useMarkRead();

  const items = useMemo(() => data?.pages.flatMap((p) => p.data) ?? [], [data]);
  const unread = data?.pages[0]?.meta.unread_count ?? 0;

  /**
   * Rows that have been cleared and are playing their exit animation.
   *
   * A cleared row does not teleport. It stays in "New" — where the user is
   * looking — long enough to collapse and fade out of it, and only then does it
   * re-section into "Earlier". So the sequence the user sees is: the row shrinks
   * away, the list closes the gap, and the row is now in the other section.
   *
   * Membership is by id and lives only for the length of the animation, which
   * is why this cannot get stuck: {@link onExited} clears it from the
   * animation's own completion callback.
   */
  const [exiting, setExiting] = useState<Record<string, true>>({});

  const onExited = useCallback((id: string) => {
    setExiting((prev) => {
      if (!(id in prev)) return prev;
      const next = { ...prev };
      delete next[id];

      return next;
    });
  }, []);

  /**
   * Two sections — "New" and "Earlier" — flattened into one list rather than a
   * SectionList, because FlashList's recycling is what keeps a long history
   * cheap and a section header is just another row type.
   */
  const rows = useMemo<Row[]>(() => {
    const isNew = (i: NotificationRow) => i.read_at === null || i.id in exiting;
    const newOnes = items.filter(isNew);
    const earlier = items.filter((i) => !isNew(i));
    const out: Row[] = [];
    if (newOnes.length > 0) {
      out.push({ kind: 'header', label: t('notifications.new') });
      out.push(...newOnes.map((item) => ({ kind: 'row' as const, item })));
    }
    if (earlier.length > 0) {
      out.push({ kind: 'header', label: t('notifications.earlier') });
      out.push(...earlier.map((item) => ({ kind: 'row' as const, item })));
    }
    return out;
  }, [items, exiting, t]);

  const onPressRow = useCallback(
    (item: NotificationRow) => {
      // Mark read first: the navigation unmounts this screen, and a mutation
      // fired after that still completes but has no list left to update
      // optimistically.
      if (item.read_at === null) markRead.mutate({ ids: [item.id] });
      if (item.url) router.push(item.url as never);
    },
    [markRead],
  );

  /**
   * Clear one row's unread state without opening it. Marking it `exiting` FIRST
   * is what holds it in "New" while it animates; the mutation's optimistic
   * update lands on the same frame and would otherwise re-section it instantly.
   */
  const onMarkRead = useCallback(
    (item: NotificationRow) => {
      setExiting((prev) => ({ ...prev, [item.id]: true }));
      markRead.mutate({ ids: [item.id] });
    },
    [markRead],
  );

  const onEndReached = useCallback(() => {
    if (hasNextPage && !isFetchingNextPage) void fetchNextPage();
  }, [hasNextPage, isFetchingNextPage, fetchNextPage]);

  /** A refresh rebuilds the list, so any in-flight exit animation is moot. */
  const onRefresh = useCallback(() => {
    setExiting({});
    void refetch();
  }, [refetch]);

  const renderItem = useCallback(
    ({ item }: { item: Row }) =>
      item.kind === 'header' ? (
        <Text style={styles.sectionHeader}>{item.label}</Text>
      ) : (
        <NotificationCard
          item={item.item}
          styles={styles}
          c={c}
          t={t}
          exiting={item.item.id in exiting}
          onPress={onPressRow}
          onMarkRead={onMarkRead}
          onExited={onExited}
        />
      ),
    [styles, c, t, exiting, onPressRow, onMarkRead, onExited],
  );

  /*
   * THREE row shapes, and the recycler has to know all three apart: a section
   * header, an unread row (wrapped in a `Swipeable`), and a read row (a bare
   * `Pressable`). Anything the recycler considers one type it will hand another
   * item's view to.
   *
   * Splitting unread from read is not cosmetic: an unread row is a `Swipeable`
   * wrapping a `Pressable`, a read row is the `Pressable` alone. Handing the
   * recycler one where it expects the other makes it reconcile two different
   * trees in a reused cell — and marking a row read flips it from one shape to
   * the other, so this happens on the most ordinary interaction the screen has.
   */
  const getItemType = useCallback(
    (row: Row) => (row.kind === 'header' ? 'header' : row.item.read_at === null ? 'row-unread' : 'row-read'),
    [],
  );

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader
        title={t('notifications.title')}
        divided
        right={
          unread > 0 ? (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('notifications.markAll')}
              onPress={() => markRead.mutate({ all: true })}
              hitSlop={10}
              testID="mark-all-read"
            >
              <Text style={styles.markAll}>{t('notifications.markAll')}</Text>
            </Pressable>
          ) : undefined
        }
      />

      {isLoading ? (
        <ActivityIndicator color={c.primary} style={styles.loading} />
      ) : isError ? (
        <View style={styles.empty} testID="notifications-error">
          <Ionicons name="alert-circle-outline" size={40} color={c.muted} />
          <Text style={styles.emptyText}>{t('notifications.error')}</Text>
          <Pressable accessibilityRole="button" onPress={() => void refetch()} style={styles.retry}>
            <Text style={styles.retryText}>{t('common.tryAgain')}</Text>
          </Pressable>
        </View>
      ) : (
        <FlashList
          data={rows}
          renderItem={renderItem}
          getItemType={getItemType}
          keyExtractor={(row) => (row.kind === 'header' ? `h-${row.label}` : row.item.id)}
          contentContainerStyle={styles.list}
          onEndReached={onEndReached}
          onEndReachedThreshold={0.5}
          refreshControl={<RefreshControl refreshing={isRefetching} onRefresh={onRefresh} tintColor={c.primary} />}
          ListEmptyComponent={
            <View style={styles.empty} testID="notifications-empty">
              <Ionicons name="notifications-outline" size={40} color={c.muted} />
              <Text style={styles.emptyText}>{t('notifications.empty')}</Text>
            </View>
          }
          ListFooterComponent={isFetchingNextPage ? <ActivityIndicator style={styles.footer} color={c.primary} /> : null}
        />
      )}
    </SafeAreaView>
  );
}

function NotificationCard({
  item,
  styles,
  c,
  t,
  onPress,
  exiting,
  onMarkRead,
  onExited,
}: {
  item: NotificationRow;
  styles: Styles;
  c: Palette;
  t: ReturnType<typeof useT>;
  exiting: boolean;
  onPress: (item: NotificationRow) => void;
  onMarkRead: (item: NotificationRow) => void;
  onExited: (id: string) => void;
}) {
  const unread = item.read_at === null;
  const swipeRef = useRef<Swipeable>(null);
  const markLabel = t('notifications.markRead');
  const reduceMotion = useReduceMotion();

  /**
   * 1 = fully present, 0 = gone. Clearing a row COLLAPSES it out of the unread
   * list — it fades and its height shrinks to nothing, so the rows below slide
   * up to close the gap — and only when that finishes does it re-appear under
   * "Earlier". The row is never yanked from under the finger that tapped it.
   */
  // `useState`, not `useRef`: an Animated.Value read during render to build the
  // interpolations below, and a ref read at render time is what the lint rule
  // (rightly) forbids. The lazy initialiser still constructs it once.
  const [presence] = useState(() => new Animated.Value(1));
  // Measured once from a real layout pass — a height animation needs a number
  // to count down from, and `auto` is not one.
  const [naturalHeight, setNaturalHeight] = useState(0);

  useEffect(() => {
    if (!exiting) return;

    /*
     * Duration 0 under reduce-motion rather than an early `onExited()` call:
     * the completion callback is asynchronous either way, so the row still
     * leaves through exactly one code path, and there is no `setState` in an
     * effect body for the lint rule to (correctly) object to.
     */
    const animation = Animated.timing(presence, {
      toValue: 0,
      duration: reduceMotion === false && naturalHeight > 0 ? 260 : 0,
      // Height cannot be driven natively.
      useNativeDriver: false,
    });
    animation.start(({ finished }) => {
      if (finished) onExited(item.id);
    });

    return () => animation.stop();
  }, [exiting, reduceMotion, naturalHeight, presence, onExited, item.id]);

  /**
   * Applied only while leaving. A row at rest keeps `height: auto`, because
   * pinning every row to a measured pixel height would make a two-line body
   * that later wraps to three clip itself.
   */
  const exitStyle = exiting
    ? {
        opacity: presence,
        height: naturalHeight > 0 ? presence.interpolate({ inputRange: [0, 1], outputRange: [0, naturalHeight] }) : undefined,
        marginBottom: presence.interpolate({ inputRange: [0, 1], outputRange: [0, space.sm] }),
      }
    : null;

  const markRead = () => {
    swipeRef.current?.close();
    onMarkRead(item);
  };

  /**
   * Left-swipe reveals "mark read", mirroring the my-places card (T-071) —
   * same `Swipeable` mechanics, same friction and threshold — but in `primary`
   * rather than `danger`, because clearing a badge is not a destructive act and
   * should not borrow the colour that means "this deletes something".
   */
  const renderRightActions = (
    _progress: Animated.AnimatedInterpolation<number>,
    dragX: Animated.AnimatedInterpolation<number>,
  ) => {
    // Same interpolation ranges as the my-places card, so the two swipes feel
    // identical under the thumb rather than merely looking alike.
    const scale = dragX.interpolate({ inputRange: [-96, -24, 0], outputRange: [1, 0.86, 0.4], extrapolate: 'clamp' });
    const opacity = dragX.interpolate({ inputRange: [-72, -24, 0], outputRange: [1, 0.5, 0], extrapolate: 'clamp' });

    return (
      <RectButton
        accessibilityLabel={markLabel}
        onPress={markRead}
        style={styles.swipeAction}
        testID={`mark-read-swipe-${item.id}`}
      >
        <Animated.View style={[styles.swipeInner, { opacity, transform: [{ scale }] }]}>
          <Ionicons name="mail-open-outline" size={22} color={c.onPrimary} />
          <Text style={styles.swipeText}>{markLabel}</Text>
        </Animated.View>
      </RectButton>
    );
  };
  // Resolved in the CURRENT language from `type` + params — an unknown type from
  // a newer server still renders, falling back to the copy that server sent.
  const { title, body } = notificationCopy(t, item);
  const elapsed = elapsedSince(item.created_at);
  // `now` needs no count, but passing one is harmless — `time.now` has no
  // placeholder to fill — so the unit maps straight to its key with no special
  // case.
  const when = elapsed ? t(`time.${elapsed.unit}`, { count: elapsed.value }) : null;

  const card = (
    <Animated.View style={[styles.rowOuter, exitStyle]}>
      <View
        style={[styles.rowChrome, unread && styles.rowChromeUnread]}
        onLayout={(e) => setNaturalHeight(e.nativeEvent.layout.height)}
      >
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={body ? `${title}. ${body}` : title}
          accessibilityState={{ selected: unread }}
          onPress={() => onPress(item)}
          style={({ pressed }) => [styles.row, pressed && styles.pressed]}
          testID={`notification-${item.id}`}
        >
        <Ionicons name={ICONS[item.type] ?? 'notifications'} size={22} color={tintFor(c, item.type)} />
        <View style={styles.rowBody}>
          <Text style={styles.rowTitle} numberOfLines={1}>
            {title}
          </Text>
          {body ? (
            <Text style={styles.rowText} numberOfLines={2}>
              {body}
            </Text>
          ) : null}
        </View>
        {/* "3d" answers the question every notification list gets asked — is this
            new, or have I already dealt with it? The unread dot alone doesn't. */}
          {when ? <Text style={styles.rowWhen}>{when}</Text> : null}
        </Pressable>

        {/*
          A SIBLING of the card's own pressable, never a child of it.

          Nested, the two overlap and a tap near the dot can fall through to the
          card underneath — which navigates. "I tapped the circle and it opened
          a different screen" is exactly that. As siblings there is no ambiguity
          left to get wrong: the dot's box is the dot's, the rest is the card's.

          The dot is both the unread indicator and the button that clears it —
          tapping the thing that means "unread" to make it not-unread needs no
          explaining — and it is the accessible route to the action, since a
          swipe is invisible to a screen reader.
        */}
        {unread ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={markLabel}
            onPress={markRead}
            hitSlop={12}
            style={styles.dotHit}
            testID={`mark-read-${item.id}`}
          >
            <View style={styles.dot} testID="unread-dot" />
          </Pressable>
        ) : (
          // Holds the dot's width so a read row's timestamp stays on the same
          // vertical line as an unread one's.
          <View style={styles.dotHit} pointerEvents="none" />
        )}
      </View>
    </Animated.View>
  );

  // A read row has nothing to mark, so it gets no swipe — an action that
  // silently does nothing is worse than no action.
  if (!unread) return card;

  return (
    <Swipeable
      /*
       * Keyed by the row id so a recycled cell gets a FRESH Swipeable rather
       * than one carrying another notification's gesture state. `Swipeable`
       * keeps open/closed position internally, and FlashList reuses cells as
       * you scroll — without this, a row can inherit a half-open offset from
       * whichever item last used that cell.
       */
      key={item.id}
      ref={swipeRef}
      friction={2}
      rightThreshold={44}
      overshootRight={false}
      renderRightActions={renderRightActions}
    >
      {card}
    </Swipeable>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    loading: { paddingVertical: space.xl },
    // xxl, matching the offers list: `edges` covers only the top, so the last
    // row needs room to clear the home indicator instead of ending under it.
    list: { paddingHorizontal: space.md, paddingBottom: space.xxl },
    markAll: { ...type.bodySm, color: c.primary, fontWeight: '700' },
    sectionHeader: {
      ...type.caption,
      color: c.muted,
      textTransform: 'uppercase',
      letterSpacing: 0.4,
      // No bottom padding: the 12pt separator already sits between the header
      // and its first row, so adding both stacked 20pt in there.
      paddingTop: space.sm,
    },
    // The animating shell: height/opacity/margin live here so the card's own
    // padding and corners never have to take part in the collapse.
    rowOuter: { marginBottom: space.sm, overflow: 'hidden' },
    /*
     * The card's SKIN and the row axis. The hairline is what makes a spaced row
     * read as a card rather than a floating patch of white — the same treatment
     * the my-places card uses — and the border is always present, transparent
     * when read, so switching states cannot shift the content by a pixel.
     */
    rowChrome: {
      flexDirection: 'row',
      alignItems: 'center',
      paddingRight: space.xs,
      borderRadius: radius.md,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: 'transparent',
    },
    rowChromeUnread: { backgroundColor: c.surface, borderColor: c.border },
    row: {
      flex: 1,
      flexDirection: 'row',
      alignItems: 'center',
      gap: space.sm,
      paddingVertical: space.sm,
      paddingLeft: space.sm,
    },
    pressed: { opacity: 0.6 },
    rowBody: { flex: 1, gap: space.xxs },
    rowTitle: { ...type.bodyLg, color: c.text },
    rowText: { ...type.bodySm, color: c.muted },
    rowWhen: { ...type.caption, color: c.muted },
    // The visual dot stays 8pt; the padding around it is what makes the tap
    // target reachable without the indicator growing into a blob.
    dotHit: { padding: space.xs },
    dot: { width: 8, height: 8, borderRadius: radius.pill, backgroundColor: c.primary },
    swipeAction: {
      width: 108,
      marginLeft: space.xs,
      borderRadius: radius.md,
      backgroundColor: c.primary,
      alignItems: 'center',
      justifyContent: 'center',
    },
    swipeInner: { alignItems: 'center', gap: space.xxs, paddingHorizontal: space.xs },
    swipeText: { ...type.caption, color: c.onPrimary, fontWeight: '700', textAlign: 'center' },
    footer: { paddingVertical: space.md },
    empty: { alignItems: 'center', gap: space.xs, paddingTop: space.xxl, paddingHorizontal: space.xl },
    emptyText: { ...type.body, color: c.muted, textAlign: 'center' },
    retry: {
      marginTop: space.sm,
      paddingHorizontal: space.lg,
      paddingVertical: space.xs,
      borderRadius: radius.md,
      borderWidth: 1.5,
      borderColor: c.primary,
    },
    retryText: { ...type.body, color: c.primary, fontWeight: '600' },
  });
