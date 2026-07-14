import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useMemo } from 'react';
import { Pressable, StyleSheet, Text } from 'react-native';

import { useT } from '@/i18n';
import { useSessionStore } from '@/stores/session';
import { type Palette, useColors } from '@/theme/colors';

/**
 * Nudge shown to an authed-but-unconfirmed account (T-066). The first session
 * is usable, but after logging out the user can't log back in until they
 * confirm — so this banner links to the verify flow prefilled with their email.
 * Renders nothing once the email is verified (or for guests).
 */
export function VerifyEmailBanner() {
  const t = useT();
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  const user = useSessionStore((s) => s.user);
  const status = useSessionStore((s) => s.status);

  if (status !== 'authed' || !user || user.email_verified_at) {
    return null;
  }

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={t('verify.bannerAction')}
      onPress={() => router.push({ pathname: '/(auth)/verify-email', params: { email: user.email } })}
      style={({ pressed }) => [styles.banner, pressed && styles.pressed]}
    >
      <Ionicons name="mail-unread-outline" size={20} color={c.primary} />
      <Text style={styles.text} numberOfLines={2}>
        {t('verify.banner')}
      </Text>
      <Text style={styles.action}>{t('verify.bannerAction')}</Text>
    </Pressable>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    banner: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      padding: 12,
      marginBottom: 12,
      borderRadius: 12,
      backgroundColor: c.primarySoft,
    },
    pressed: { opacity: 0.7 },
    text: { flex: 1, fontSize: 13, color: c.text },
    action: { fontSize: 13, fontWeight: '700', color: c.primary },
  });
