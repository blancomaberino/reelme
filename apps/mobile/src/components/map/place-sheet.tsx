import { Ionicons } from '@expo/vector-icons';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { MapPin } from '@/api/places';
import { useT } from '@/i18n';
import { useFormat } from '@/lib/use-format';
import { fonts, type Palette, useColors } from '@/theme/colors';

type Props = {
  pin: MapPin;
  onViewPlace: (slug: string) => void;
  /** When set (authed viewers), shows a "save to a list" action (T-073). */
  onSave?: (pinId: string) => void;
};

/**
 * Bottom-sheet preview for a tapped pin (T-032 §6). The map pin summary lacks a
 * slug, so "View place" routes by id — the place route binding accepts both
 * (T-030). Tapping another pin swaps this content in place (no dismiss/reopen).
 */
export function PlaceSheet({ pin, onViewPlace, onSave }: Props) {
  const c = useColors();
  const t = useT();
  const fmt = useFormat();
  const styles = useMemo(() => makeStyles(c), [c]);
  const line = fmt.priceLine(pin.category, pin.price_range);

  return (
    <View style={styles.container}>
      <Text style={styles.name} numberOfLines={1}>
        {pin.name}
      </Text>
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
        {onSave ? (
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
