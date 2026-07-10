import { useMemo } from 'react';
import {
  ActivityIndicator,
  Pressable,
  type PressableProps,
  StyleSheet,
  Text,
} from 'react-native';

import { type Palette, useColors } from '@/theme/colors';

type Props = Omit<PressableProps, 'children'> & {
  title: string;
  variant?: 'primary' | 'secondary';
  loading?: boolean;
};

export function Button({ title, variant = 'primary', loading, disabled, style, ...props }: Props) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  const isPrimary = variant === 'primary';

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: !!(disabled || loading), busy: !!loading }}
      disabled={disabled || loading}
      style={(state) => [
        styles.base,
        isPrimary ? styles.primary : styles.secondary,
        isPrimary && state.pressed && styles.primaryPressed,
        (disabled || loading) && styles.disabled,
        state.pressed && styles.pressed,
        typeof style === 'function' ? style(state) : style,
      ]}
      {...props}
    >
      {loading ? (
        <ActivityIndicator color={isPrimary ? c.onPrimary : c.primary} />
      ) : (
        <Text style={[styles.text, isPrimary ? styles.textPrimary : styles.textSecondary]}>{title}</Text>
      )}
    </Pressable>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    base: {
      borderRadius: 14,
      paddingVertical: 16,
      alignItems: 'center',
      justifyContent: 'center',
    },
    primary: {
      backgroundColor: c.primary,
      // Subtle brand-tinted elevation on the primary CTA.
      shadowColor: c.primary,
      shadowOpacity: 0.3,
      shadowRadius: 12,
      shadowOffset: { width: 0, height: 6 },
      elevation: 3,
    },
    primaryPressed: { backgroundColor: c.primaryPressed },
    secondary: { backgroundColor: 'transparent', borderWidth: 1.5, borderColor: c.primary },
    disabled: { opacity: 0.5 },
    pressed: { transform: [{ scale: 0.985 }] },
    text: { fontSize: 16, fontWeight: '600' },
    textPrimary: { color: c.onPrimary },
    textSecondary: { color: c.primary },
  });
