import { Ionicons } from '@expo/vector-icons';
import { useMemo } from 'react';
import {
  ActivityIndicator,
  Pressable,
  type PressableProps,
  StyleSheet,
  Text,
  View,
} from 'react-native';

import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * `primary`  — the one CTA on a screen. Filled, brand-tinted elevation.
 * `secondary`— the alternative to the CTA. Outlined.
 * `danger`   — destructive and irreversible (delete a list, discard a share).
 * `ghost`    — a tertiary action that must not compete: no fill, no border.
 * `link`     — inline text action. Reads as a link, not a button-shaped thing.
 *
 * Before T-104 this had two variants and one size, so only 13 of 29 screens
 * imported it — the rest hand-rolled a Pressable, and each one re-invented the
 * padding, the pressed state and the disabled opacity.
 */
type Variant = 'primary' | 'secondary' | 'danger' | 'ghost' | 'link';

/** `sm` for a row-inline action, `md` everywhere else. */
type Size = 'sm' | 'md';

type Props = Omit<PressableProps, 'children'> & {
  title: string;
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  /** Leading icon. Sized and coloured to match the label automatically. */
  icon?: keyof typeof Ionicons.glyphMap;
};

/** Which palette colour the label — and the icon, and the spinner — takes. */
function labelColor(c: Palette, variant: Variant): string {
  return {
    primary: c.onPrimary,
    secondary: c.primary,
    danger: c.onPrimary,
    ghost: c.text,
    link: c.primary,
  }[variant];
}

export function Button({
  title,
  variant = 'primary',
  size = 'md',
  loading,
  icon,
  disabled,
  style,
  ...props
}: Props) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  const tint = labelColor(c, variant);
  // `link` is text, not a control surface: no padding, no fill, no press-scale,
  // so it can sit inline in a sentence without pushing it around.
  const isLink = variant === 'link';

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: !!(disabled || loading), busy: !!loading }}
      disabled={disabled || loading}
      style={(state) => [
        isLink ? styles.linkBase : styles.base,
        !isLink && (size === 'sm' ? styles.sizeSm : styles.sizeMd),
        styles[variant],
        variant === 'primary' && state.pressed && styles.primaryPressed,
        variant === 'danger' && state.pressed && styles.dangerPressed,
        (disabled || loading) && styles.disabled,
        state.pressed && !isLink && styles.pressed,
        state.pressed && isLink && styles.linkPressed,
        typeof style === 'function' ? style(state) : style,
      ]}
      {...props}
    >
      {loading ? (
        <ActivityIndicator color={tint} />
      ) : (
        <View style={styles.content}>
          {icon ? <Ionicons name={icon} size={size === 'sm' ? 16 : 18} color={tint} /> : null}
          <Text style={[styles.text, isLink && styles.linkText, { color: tint }]}>{title}</Text>
        </View>
      )}
    </Pressable>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    base: {
      borderRadius: radius.lg,
      alignItems: 'center',
      justifyContent: 'center',
    },
    // Snapped to the scale rather than preserved exactly: this was 15/28 with a
    // 14 radius, none of which is a step. The deltas (+1 vertical, +4
    // horizontal, +2 radius) are sub-perceptual on a filled button and are the
    // point of adopting a ramp — arithmetic like `space.md - 1` would keep the
    // pixels and the drift.
    sizeMd: { paddingVertical: space.md, paddingHorizontal: space.xl },
    sizeSm: { paddingVertical: space.xs, paddingHorizontal: space.md },
    linkBase: { alignSelf: 'flex-start' },

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
    danger: { backgroundColor: c.danger },
    dangerPressed: { opacity: 0.85 },
    ghost: { backgroundColor: 'transparent' },
    link: { backgroundColor: 'transparent' },

    disabled: { opacity: 0.5 },
    pressed: { transform: [{ scale: 0.985 }] },
    linkPressed: { opacity: 0.6 },

    content: { flexDirection: 'row', alignItems: 'center', gap: space.xs },
    text: type.bodyLg,
    linkText: { textDecorationLine: 'underline' },
  });
