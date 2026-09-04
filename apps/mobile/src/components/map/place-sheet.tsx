import { Ionicons } from '@expo/vector-icons';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { MapPin } from '@/api/places';
import { useT } from '@/i18n';
import { openStateLabel } from '@/lib/opening-hours';
import { useAgeOf } from '@/lib/use-age-of';
import { useFormat } from '@/lib/use-format';
import { fonts, type Palette, useColors } from '@/theme/colors';

type Props = {
  pin: MapPin;
  onViewPlace: (slug: string) => void;
  /** When set (authed viewers), shows a "save to a list" action (T-073). */
  onSave?: (pinId: string) => void;
  /**
   * When set (a list is the active map scope, owner viewing their own list),
   * replaces the save action with "remove from this list" (T-073 follow-up).
   */
  onRemoveFromList?: (pinId: string) => void;
  /**
   * When the pins on screen were fetched (react-query's `dataUpdatedAt`). Ages
   * the open/closed cue out — see {@link openStateLabel}. Zero means "unknown",
   * which reads as a huge age and therefore no cue: the honest default, since a
   * caller that forgot to pass it has no idea how old its data is either.
   */
  fetchedAt?: number;
};

/**
 * Bottom-sheet preview for a tapped pin (T-032 §6). The map pin summary lacks a
 * slug, so "View place" routes by id — the place route binding accepts both
 * (T-030). Tapping another pin swaps this content in place (no dismiss/reopen).
 */
export function PlaceSheet({ pin, onViewPlace, onSave, onRemoveFromList, fetchedAt = 0 }: Props) {
  const c = useColors();
  const t = useT();
  const fmt = useFormat();
  const styles = useMemo(() => makeStyles(c), [c]);
  const line = fmt.priceLine(pin.category, pin.price_range);

  // "Can I go there, now" — the pair T-156 exists to put on this sheet, and the
  // reason it sits directly under the name rather than in the meta row: it is
  // the question someone with a map open is actually asking.
  //
  // Both are rendered only when the SERVER sent them, and each independently.
  // `distance_m` is absent unless the request carried the viewer's position, and
  // `open_state` is additionally null whenever the answer is not knowable — no
  // structured periods, or no timezone for the venue. A null must render as NO
  // CUE. Never "Cerrado": telling someone a place is shut when nobody knows
  // sends them away from a restaurant that is open and wanted them, and that is
  // exactly the wrong answer T-128 deleted and T-155 refused to reinstate.
  //
  // The age is the payload's, not the app's: past five minutes the cue drops
  // itself, because the map query is persisted for a day and a cold start would
  // otherwise repaint last night's "Abierto".
  const openState = openStateLabel(pin.open_state, useAgeOf(fetchedAt));
  const distance = fmt.distance(pin.distance_m);

  return (
    <View style={styles.container}>
      <Text style={styles.name} numberOfLines={1}>
        {pin.name}
      </Text>
      {openState || distance ? (
        <View style={styles.metaRow} testID="place-sheet-status">
          {openState ? (
            <Text style={openState.open ? styles.openCue : styles.closedCue}>
              {t(openState.key, openState.vars)}
            </Text>
          ) : null}
          {distance ? (
            <Text style={styles.muted} testID="place-sheet-distance">
              {distance}
            </Text>
          ) : null}
        </View>
      ) : null}
      <View style={styles.metaRow}>
        {line ? <Text style={styles.meta}>{line}</Text> : null}
        {pin.city ? <Text style={styles.muted}>{pin.city}</Text> : null}
      </View>
      {pin.top_influencer ? (
        <View style={styles.attr}>
          <Ionicons name="star" size={13} color={c.primary} />
          <Text style={styles.attrText} numberOfLines={1}>
            @{pin.top_influencer.handle}
          </Text>
        </View>
      ) : null}
      {pin.tags.length > 0 ? (
        <Text style={styles.tags} numberOfLines={1}>
          {pin.tags.slice(0, 4).map(fmt.tag).join(' · ')}
        </Text>
      ) : null}

      <View style={styles.actions}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('place.view')}
          onPress={() => onViewPlace(pin.id)}
          style={({ pressed }) => [styles.button, pressed && styles.buttonPressed]}
        >
          <Text style={styles.buttonLabel}>{t('place.view')}</Text>
          <Ionicons name="arrow-forward" size={18} color={c.onPrimary} />
        </Pressable>
        {onRemoveFromList ? (
          // A list is the active scope: the pin is already in it, so the filled
          // bookmark reads "saved here — tap to remove from this list".
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t('map.removeFromList')}
            onPress={() => onRemoveFromList(pin.id)}
            style={({ pressed }) => [styles.saveButton, pressed && styles.buttonPressed]}
          >
            <Ionicons name="bookmark" size={20} color={c.primary} />
          </Pressable>
        ) : onSave ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t('save.title')}
            onPress={() => onSave(pin.id)}
            style={({ pressed }) => [styles.saveButton, pressed && styles.buttonPressed]}
          >
            <Ionicons name="bookmark-outline" size={20} color={c.primary} />
          </Pressable>
        ) : null}
      </View>
    </View>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    container: { paddingHorizontal: 20, paddingTop: 4, gap: 8 },
    name: { fontFamily: fonts.display, fontSize: 21, fontWeight: '700', color: c.text, letterSpacing: -0.2 },
    metaRow: { flexDirection: 'row', alignItems: 'center', gap: 10, flexWrap: 'wrap' },
    meta: { fontSize: 15, color: c.text, textTransform: 'capitalize' },
    muted: { fontSize: 14, color: c.muted },
    // The same two cue styles the place detail uses (T-155), deliberately — a
    // green that means "open" on one screen and something else on another
    // teaches the reader that colour here means nothing.
    openCue: { fontSize: 15, color: c.green, fontWeight: '600' },
    closedCue: { fontSize: 15, color: c.muted, fontWeight: '600' },
    attr: { flexDirection: 'row', alignItems: 'center', gap: 5 },
    attrText: { fontSize: 14, color: c.text, fontWeight: '600' },
    tags: { fontSize: 13, color: c.muted },
    actions: { marginTop: 8, flexDirection: 'row', gap: 10, alignItems: 'stretch' },
    button: {
      flex: 1,
      flexDirection: 'row',
      gap: 8,
      alignItems: 'center',
      justifyContent: 'center',
      backgroundColor: c.primary,
      borderRadius: 14,
      paddingVertical: 14,
    },
    saveButton: {
      width: 52,
      alignItems: 'center',
      justifyContent: 'center',
      backgroundColor: c.primarySoft,
      borderRadius: 14,
    },
    buttonPressed: { backgroundColor: c.primaryPressed },
    buttonLabel: { color: c.onPrimary, fontSize: 16, fontWeight: '600' },
  });
