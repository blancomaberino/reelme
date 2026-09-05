import { useMemo } from 'react';
import { Pressable, StyleSheet, Text } from 'react-native';

import { type Palette, useColors } from '@/theme/colors';

type Props = {
  label: string;
  onPress?: () => void;
  /**
   * Filled rather than soft, and announced as selected (T-158).
   *
   * Added here instead of in a second "filter chip" component: a chip that can
   * be on is the same object as a chip that is off, and two components would
   * have drifted on padding within a release.
   */
  selected?: boolean;
};

/** A small pill for tags / filters. Inert (plain view) when no onPress given. */
export function Chip({ label, onPress, selected = false }: Props) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);

  return (
    <Pressable
      disabled={!onPress}
      onPress={onPress}
      accessibilityRole={onPress ? 'button' : 'text'}
      accessibilityState={onPress ? { selected } : undefined}
      style={({ pressed }) => [
        styles.chip,
        selected ? styles.chipSelected : null,
        pressed && onPress ? styles.pressed : null,
      ]}
    >
      <Text style={[styles.label, selected ? styles.labelSelected : null]} numberOfLines={1}>
        {label}
      </Text>
    </Pressable>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    chip: {
      paddingHorizontal: 12,
      paddingVertical: 6,
      borderRadius: 999,
      backgroundColor: c.secondarySoft,
    },
    chipSelected: { backgroundColor: c.primary },
    pressed: { opacity: 0.6 },
    label: { color: c.secondary, fontSize: 13, fontWeight: '600' },
    labelSelected: { color: c.onPrimary },
  });
