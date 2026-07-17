import { Ionicons } from '@expo/vector-icons';
import { useMemo } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';

/** One applied filter, shown as a removable pill beside the Filters button. */
export type AppliedChip = { key: string; label: string; onRemove: () => void };

type Props = {
  /** How many filters are active — drives the badge on the button. */
  count: number;
  onOpen: () => void;
  chips: AppliedChip[];
  /** Floating shadow so the bar reads above the map; flat on opaque screens. */
  elevated?: boolean;
};

/**
 * The compact filter bar: a leading "Filtros" button (with an active-count
 * badge) that opens the filter sheet, followed by the currently-applied filters
 * as removable chips. Keeps the bar short no matter how many options exist —
 * replacing the old always-on horizontal chip list.
 */
export function FilterTriggerBar({ count, onOpen, chips, elevated }: Props) {
  const t = useT();
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  const active = count > 0;

  return (
    <ScrollView
      horizontal
      showsHorizontalScrollIndicator={false}
      style={styles.scroll}
      contentContainerStyle={styles.row}
      keyboardShouldPersistTaps="handled"
    >
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={t('filters.title')}
        // Announce the active count (the numeric badge below isn't read on its
        // own) without changing the label.
        accessibilityValue={active ? { text: String(count) } : undefined}
        onPress={onOpen}
        style={({ pressed }) => [
          styles.trigger,
          elevated && styles.elevated,
          active && styles.triggerActive,
          pressed && styles.pressed,
        ]}
      >
        <Ionicons name="options-outline" size={16} color={active ? c.primary : c.text} />
        <Text style={[styles.triggerLabel, active && styles.triggerLabelActive]}>{t('filters.title')}</Text>
        {active ? (
          <View style={styles.badge}>
            <Text style={styles.badgeText}>{count}</Text>
          </View>
        ) : null}
      </Pressable>

      {chips.map((chip) => (
        <Pressable
          key={chip.key}
          accessibilityRole="button"
          accessibilityLabel={t('filters.remove', { label: chip.label })}
          onPress={chip.onRemove}
          style={({ pressed }) => [styles.chip, elevated && styles.elevated, pressed && styles.pressed]}
        >
          <Text style={styles.chipLabel} numberOfLines={1}>
            {chip.label}
          </Text>
          <Ionicons name="close" size={14} color={c.ink2} />
        </Pressable>
      ))}
    </ScrollView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    // flexGrow:0 so the row hugs its chips in a flex-column parent (My places).
    scroll: { flexGrow: 0 },
    row: { gap: 8, paddingHorizontal: 12, paddingVertical: 8, alignItems: 'center' },
    trigger: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 6,
      paddingHorizontal: 14,
      paddingVertical: 8,
      borderRadius: 999,
      backgroundColor: c.surface,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
    },
    triggerActive: { borderColor: c.primary },
    triggerLabel: { color: c.text, fontSize: 13, fontWeight: '700' },
    triggerLabelActive: { color: c.primary },
    badge: {
      minWidth: 18,
      height: 18,
      paddingHorizontal: 5,
      borderRadius: 9,
      backgroundColor: c.primary,
      alignItems: 'center',
      justifyContent: 'center',
    },
    badgeText: { color: c.onPrimary, fontSize: 11, fontWeight: '800' },
    chip: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 6,
      paddingLeft: 14,
      paddingRight: 10,
      paddingVertical: 8,
      borderRadius: 999,
      backgroundColor: c.surface,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      maxWidth: 200,
    },
    chipLabel: { color: c.text, fontSize: 13, fontWeight: '600', flexShrink: 1 },
    // A slight shadow so pills read above the map (matches the old FilterBar).
    elevated: {
      shadowColor: '#000',
      shadowOpacity: 0.12,
      shadowRadius: 4,
      shadowOffset: { width: 0, height: 1 },
      elevation: 2,
    },
    pressed: { opacity: 0.7 },
  });
