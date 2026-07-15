import { Ionicons } from '@expo/vector-icons';
import { memo, useMemo, useRef } from 'react';
import { Animated, Pressable, StyleSheet, Text, View } from 'react-native';
import { RectButton, Swipeable } from 'react-native-gesture-handler';

import type { PlaceSummary } from '@/api/places';
import { Thumbnail } from '@/components/place/thumbnail';
import { useT } from '@/i18n';
import { useFormat } from '@/lib/use-format';
import { fonts, type Palette, useColors } from '@/theme/colors';

type Props = {
  place: PlaceSummary;
  onPress: (slug: string) => void;
  /** When provided, enables "remove from my map" — a ⋯ button + left-swipe. */
  onRemove?: (place: PlaceSummary) => void;
};

/**
 * One row of the personal "my places" list (T-071) — the list twin of a map
 * pin: poster, name, cuisine/price, city, and a saved marker. Left-swipe or the
 * ⋯ button removes it from my collection (dismiss my share / un-save).
 */
function MyPlaceCardBase({ place, onPress, onRemove }: Props) {
  const c = useColors();
  const t = useT();
  const fmt = useFormat();
  const styles = useMemo(() => makeStyles(c), [c]);
  const swipeRef = useRef<Swipeable>(null);

  const line = fmt.priceLine(place.category, place.price_range);
  const label = t('myPlaces.remove');
  // A pin that's mine only through a save (not one of my shares) gets the marker.
  const savedOnly = !!place.mine?.saved && !place.mine?.share_id;

  const triggerRemove = () => {
    swipeRef.current?.close();
    onRemove?.(place);
  };

  const renderRightActions = (
    _progress: Animated.AnimatedInterpolation<number>,
    dragX: Animated.AnimatedInterpolation<number>,
  ) => {
    const scale = dragX.interpolate({ inputRange: [-96, -24, 0], outputRange: [1, 0.86, 0.4], extrapolate: 'clamp' });
    const opacity = dragX.interpolate({ inputRange: [-72, -24, 0], outputRange: [1, 0.5, 0], extrapolate: 'clamp' });
    return (
      <RectButton accessibilityLabel={label} onPress={triggerRemove} style={styles.swipeAction}>
        <Animated.View style={[styles.swipeInner, { opacity, transform: [{ scale }] }]}>
          <Ionicons name="trash-outline" size={22} color={c.onPrimary} />
          <Text style={styles.swipeText}>{label}</Text>
        </Animated.View>
      </RectButton>
    );
  };

  const card = (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={place.name}
      onPress={() => onPress(place.slug)}
      style={({ pressed }) => [styles.card, pressed && styles.pressed]}
    >
      <Thumbnail uri={place.thumbnail_url ?? null} style={styles.thumb} />
      <View style={styles.body}>
        <Text style={styles.name} numberOfLines={1}>
          {place.name}
        </Text>
        <View style={styles.metaRow}>
          {line ? <Text style={styles.meta}>{line}</Text> : null}
          {place.city ? <Text style={styles.muted}>{place.city}</Text> : null}
        </View>
        <View style={styles.badgeRow}>
          {savedOnly ? (
            <View style={styles.savedChip}>
              <Ionicons name="bookmark" size={11} color={c.primary} />
              <Text style={styles.savedText}>{t('myPlaces.saved')}</Text>
            </View>
          ) : null}
          {place.rating?.google?.value != null ? (
            <View style={styles.ratingChip}>
              <Text style={styles.ratingText}>★ {place.rating.google.value.toFixed(1)}</Text>
            </View>
          ) : null}
        </View>
      </View>
      {onRemove ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={label}
          hitSlop={10}
          onPress={triggerRemove}
          style={styles.more}
        >
          <Ionicons name="ellipsis-horizontal" size={18} color={c.muted} />
        </Pressable>
      ) : null}
    </Pressable>
  );

  if (!onRemove) return card;

  return (
    <Swipeable
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

export const MyPlaceCard = memo(MyPlaceCardBase);

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    card: {
      flexDirection: 'row',
      gap: 12,
      padding: 12,
      backgroundColor: c.surface,
      borderRadius: 16,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
    },
    pressed: { opacity: 0.7 },
    thumb: { width: 72, height: 72, borderRadius: 12 },
    body: { flex: 1, gap: 4, justifyContent: 'center' },
    name: { fontFamily: fonts.display, fontSize: 17, fontWeight: '700', color: c.text, letterSpacing: -0.2, paddingRight: 24 },
    metaRow: { flexDirection: 'row', gap: 8, alignItems: 'center', flexWrap: 'wrap' },
    meta: { fontSize: 14, color: c.text, textTransform: 'capitalize' },
    muted: { fontSize: 13, color: c.muted },
    badgeRow: { flexDirection: 'row', gap: 6, alignItems: 'center' },
    savedChip: { flexDirection: 'row', alignItems: 'center', gap: 3, backgroundColor: c.primarySoft, borderRadius: 6, paddingHorizontal: 6, paddingVertical: 2 },
    savedText: { color: c.primary, fontSize: 11, fontWeight: '700' },
    ratingChip: { backgroundColor: c.goldSoft, borderRadius: 6, paddingHorizontal: 6, paddingVertical: 2 },
    ratingText: { color: c.gold, fontSize: 11, fontWeight: '700' },
    more: { position: 'absolute', top: 8, right: 8, padding: 4 },
    swipeAction: { width: 108, marginLeft: 8, borderRadius: 16, backgroundColor: c.danger, alignItems: 'center', justifyContent: 'center' },
    swipeInner: { alignItems: 'center', gap: 4, paddingHorizontal: 8 },
    swipeText: { color: c.onPrimary, fontSize: 12, fontWeight: '700', textAlign: 'center' },
  });
