import { Ionicons } from '@expo/vector-icons';
import { useMemo } from 'react';
import { StyleSheet, Text, type TextInputProps, View } from 'react-native';

import { TextField } from '@/components/text-field';
import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';

/** Below this per-field confidence we nudge the reviewer to double-check. */
export const LOW_CONFIDENCE = 0.6;

type Props = TextInputProps & {
  label: string;
  value: string;
  onChangeText: (v: string) => void;
  /** Per-field confidence (0–1) from `extraction.confidence.per_field`, or null when unknown. */
  confidence?: number | null;
  error?: string;
};

/**
 * A labeled input that surfaces the model's per-field confidence: below
 * {@link LOW_CONFIDENCE} it tints the border gold and shows a "worth a check"
 * hint, so the reviewer's attention lands on the fields most likely wrong.
 */
export function ConfidenceField({ label, confidence, error, style, ...props }: Props) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const low = confidence != null && confidence < LOW_CONFIDENCE;

  return (
    <View style={styles.wrap}>
      <TextField
        label={label}
        error={error}
        style={[low && !error ? styles.lowInput : null, style]}
        {...props}
      />
      {low && !error ? (
        <View style={styles.hint}>
          <Ionicons name="alert-circle-outline" size={13} color={c.gold} />
          <Text style={styles.hintText}>{t('review.lowConfidence')}</Text>
        </View>
      ) : null}
    </View>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    wrap: { gap: 4 },
    lowInput: { borderColor: c.gold },
    hint: { flexDirection: 'row', alignItems: 'center', gap: 4 },
    hintText: { fontSize: 12, color: c.gold, fontWeight: '600' },
  });
