import { useMemo } from 'react';
import { Pressable, StyleSheet, Text } from 'react-native';

import { type Palette, useColors } from '@/theme/colors';

type Props = {
  label: string;
  onPress?: () => void;
};

/** A small pill for tags / filters. Inert (plain view) when no onPress given. */
export function Chip({ label, onPress }: Props) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);

  return (
    <Pressable
      disabled={!onPress}
      onPress={onPress}
      accessibilityRole={onPress ? 'button' : 'text'}
      style={({ pressed }) => [styles.chip, pressed && onPress ? styles.pressed : null]}
    >
      <Text style={styles.label} numberOfLines={1}>
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
      backgroundColor: c.primarySoft,
    },
    pressed: { opacity: 0.6 },
    label: { color: c.primary, fontSize: 13, fontWeight: '600' },
  });
