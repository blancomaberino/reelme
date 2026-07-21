import { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';

type Props = {
  label: string;
  /** 1–4, or null for unset. */
  value: number | null;
  onChange: (value: number | null) => void;
};

/** Segmented $ … $$$$ selector; tapping the active segment clears it back to "Any". */
export function PriceSelect({ label, value, onChange }: Props) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  return (
    <View style={styles.wrap}>
      <Text style={styles.label}>{label}</Text>
      <View style={styles.row}>
        {([1, 2, 3, 4] as const).map((n) => {
          const on = value === n;
          return (
            <Pressable
              key={n}
              accessibilityRole="button"
              accessibilityState={{ selected: on }}
              accessibilityLabel={'$'.repeat(n)}
              onPress={() => onChange(on ? null : n)}
              style={({ pressed }) => [styles.seg, on && styles.segOn, pressed && styles.segPressed]}
            >
              <Text style={[styles.segText, on && styles.segTextOn]}>{'$'.repeat(n)}</Text>
            </Pressable>
          );
        })}
        <Text style={styles.unset}>{value == null ? t('review.price.unset') : ''}</Text>
      </View>
    </View>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    wrap: { gap: 8 },
    label: { fontSize: 14, fontWeight: '500', color: c.text },
    row: { flexDirection: 'row', alignItems: 'center', gap: 8 },
    seg: {
      minWidth: 52,
      alignItems: 'center',
      paddingVertical: 9,
      paddingHorizontal: 10,
      borderRadius: 10,
      borderWidth: 1,
      borderColor: c.line2,
      backgroundColor: c.surface,
    },
    segOn: { borderColor: c.gold, backgroundColor: c.goldSoft },
    segPressed: { opacity: 0.6 },
    segText: { fontSize: 15, fontWeight: '700', color: c.muted },
    segTextOn: { color: c.gold },
    unset: { fontSize: 13, color: c.muted, marginLeft: 4 },
  });
