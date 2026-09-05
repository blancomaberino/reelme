import { Ionicons } from '@expo/vector-icons';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text } from 'react-native';

import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

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
    pill: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: space.xxs,
      paddingHorizontal: space.sm,
      paddingVertical: space.xs,
      borderRadius: radius.pill,
      backgroundColor: c.surface,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
    },
    pillActive: { backgroundColor: c.primary, borderColor: c.primary },
    pillPressed: { opacity: 0.7 },
    pillLabel: { ...type.bodySm, color: c.text },
    pillLabelActive: { color: c.onPrimary },
  });
