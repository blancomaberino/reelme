import { Ionicons } from '@expo/vector-icons';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text } from 'react-native';

import { type Palette, useColors } from '@/theme/colors';

/**
 * A selectable pill; fills with the accent when selected.
 *
 * Its own module rather than a member of `filter-sheet.tsx`, because it is not
 * a sheet concern: the Tonight tab (T-158) uses it with no sheet in sight, and
 * importing it from there dragged the modal chrome along with it. It was also
 * the component a later screen failed to find and briefly re-implemented.
 */
export function OptionPill({
  label,
  selected,
  icon,
  onPress,
}: {
  label: string;
  selected: boolean;
  icon?: keyof typeof Ionicons.glyphMap;
  onPress: () => void;
}) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected }}
      accessibilityLabel={label}
      onPress={onPress}
      style={({ pressed }) => [styles.pill, selected && styles.pillActive, pressed && styles.pillPressed]}
    >
      {icon ? <Ionicons name={icon} size={14} color={selected ? c.onPrimary : c.text} /> : null}
      <Text style={[styles.pillLabel, selected && styles.pillLabelActive]}>{label}</Text>
    </Pressable>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    // The LITERALS this component shipped with, deliberately kept.
    //
    // Moving a component must not restyle it. The first version of this file
    // tokenized them on the way across — gap 6→4, padding 14/9→12/8, label
    // 14/600→13/400 — which silently changed all eight pills on three screens
    // (the map filter sheet, my-places, and Tonight), and cost the selected
    // state the bold that carried it on the accent fill. Nothing pinned it and
    // the commit called it a relocation.
    //
    // Migrating these to the scale may well be right; it is a design change and
    // belongs in a change that says so, with `/frontend-design` looking at it.
    /* eslint-disable no-restricted-syntax */
    pill: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 6,
      paddingHorizontal: 14,
      paddingVertical: 9,
      borderRadius: 999,
      backgroundColor: c.surface,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
    },
    pillActive: { backgroundColor: c.primary, borderColor: c.primary },
    pillPressed: { opacity: 0.7 },
    pillLabel: { color: c.text, fontSize: 14, fontWeight: '600' },
    pillLabelActive: { color: c.onPrimary },
    /* eslint-enable no-restricted-syntax */
  });
