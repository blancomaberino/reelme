import { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { type Palette, useColors } from '@/theme/colors';

export type ChipOption = { value: string; label: string };

type Props = {
  label: string;
  options: ChipOption[];
  /** Selected values. Single-select passes at most one. */
  selected: string[];
  onToggle: (value: string) => void;
  /** accessibility hint for the group. */
  accessibilityLabel?: string;
};

/**
 * A wrapping row of toggle chips — the category single-select and the vibe /
 * dietary multi-selects on the review form both use it. Selection is driven by
 * membership in `selected`, so single vs. multi is purely how the parent's
 * `onToggle` mutates the set.
 */
export function ChipSelect({ label, options, selected, onToggle, accessibilityLabel }: Props) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);

  return (
    <View style={styles.wrap} accessibilityLabel={accessibilityLabel ?? label}>
      <Text style={styles.label}>{label}</Text>
      <View style={styles.chips}>
        {options.map((opt) => {
          const on = selected.includes(opt.value);
          return (
            <Pressable
              key={opt.value}
              accessibilityRole="button"
              accessibilityState={{ selected: on }}
              accessibilityLabel={opt.label}
              onPress={() => onToggle(opt.value)}
              style={({ pressed }) => [styles.chip, on && styles.chipOn, pressed && styles.chipPressed]}
            >
              <Text style={[styles.chipText, on && styles.chipTextOn]}>{opt.label}</Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    wrap: { gap: 8 },
    label: { fontSize: 14, fontWeight: '500', color: c.text },
    chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
    chip: {
      paddingHorizontal: 12,
      paddingVertical: 7,
      borderRadius: 999,
      borderWidth: 1,
      borderColor: c.line2,
      backgroundColor: c.surface,
    },
    chipOn: { borderColor: c.primary, backgroundColor: c.primarySoft },
    chipPressed: { opacity: 0.6 },
    chipText: { fontSize: 13, fontWeight: '600', color: c.ink2 },
    chipTextOn: { color: c.primary },
  });
