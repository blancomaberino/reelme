import { Ionicons } from '@expo/vector-icons';
import { useMemo, useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, type TextInputProps, View } from 'react-native';

import { type Palette, useColors } from '@/theme/colors';

type Props = TextInputProps & {
  label: string;
  error?: string;
};

export function TextField({ label, error, style, onFocus, onBlur, ...props }: Props) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  const [focused, setFocused] = useState(false);

  return (
    <View style={styles.container}>
      <Text style={styles.label}>{label}</Text>
      <TextInput
        accessibilityLabel={label}
        style={[styles.input, focused && styles.inputFocused, error ? styles.inputError : null, style]}
        placeholderTextColor={c.placeholder}
        selectionColor={c.primary}
        autoCapitalize="none"
        onFocus={(e) => {
          setFocused(true);
          onFocus?.(e);
        }}
        onBlur={(e) => {
          setFocused(false);
          onBlur?.(e);
        }}
        {...props}
      />
      {error ? <Text style={styles.error}>{error}</Text> : null}
    </View>
  );
}

/**
 * A form row that looks exactly like a {@link TextField} but opens something
 * instead of taking a keyboard — same label, same bordered surface, same
 * height, plus the trailing muted `chevron-forward` that is this app's "this
 * navigates" signal (PR #187: colour alone did not read as tappable).
 *
 * It shares `makeStyles` with TextField on purpose. When the two were separate
 * the label was 14 in one and 13 in the other, which is visible the moment they
 * are stacked in the same form.
 */
export function PickerField({
  label,
  value,
  placeholder,
  onPress,
  testID,
}: {
  label: string;
  /** The selected value, or null/empty for "nothing chosen yet". */
  value?: string | null;
  placeholder: string;
  onPress: () => void;
  testID?: string;
}) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  const filled = Boolean(value);

  return (
    <View style={styles.container}>
      <Text style={styles.label}>{label}</Text>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={`${label}: ${filled ? value : placeholder}`}
        testID={testID}
        onPress={onPress}
        style={({ pressed }) => [styles.input, styles.picker, pressed && styles.pickerPressed]}
      >
        <Text style={filled ? styles.pickerValue : styles.pickerPlaceholder} numberOfLines={1}>
          {filled ? value : placeholder}
        </Text>
        <Ionicons name="chevron-forward" size={18} color={c.muted} />
      </Pressable>
    </View>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    container: { gap: 6 },
    label: { fontSize: 14, fontWeight: '500', color: c.text },
    input: {
      borderWidth: 1,
      borderColor: c.border,
      borderRadius: 12,
      paddingHorizontal: 14,
      paddingVertical: 13,
      fontSize: 16,
      color: c.text,
      backgroundColor: c.surface,
    },
    inputFocused: { borderColor: c.primary, borderWidth: 1.5 },
    inputError: { borderColor: c.danger },
    error: { color: c.danger, fontSize: 13 },
    // Laid over `input`, so the box geometry can never drift from the text one.
    picker: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10 },
    pickerPressed: { opacity: 0.7 },
    pickerValue: { flex: 1, fontSize: 16, color: c.text },
    pickerPlaceholder: { flex: 1, fontSize: 16, color: c.placeholder },
  });
