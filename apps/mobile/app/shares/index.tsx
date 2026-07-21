import { Ionicons } from '@expo/vector-icons';
import { Stack } from 'expo-router';
import { useMemo } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useShares } from '@/api/hooks/useShares';
import { ShareRow } from '@/components/share/share-row';
import { useT } from '@/i18n';
import { safeBack } from '@/lib/nav';
import { fonts, type Palette, useColors } from '@/theme/colors';

/**
 * "My shares" — the ingest history, reachable from Profile (T-026). Each row
 * (shared with the composer's "Recent shares") opens the pin for a published
 * share or the status/detail screen otherwise. This is the re-entry point the
 * review flow needs now that there's no Share tab (T-077 replaced it with Search).
 */
export default function MySharesScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { data: shares, isLoading } = useShares(25);

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.header}>
        <Pressable accessibilityRole="button" accessibilityLabel={t('place.back')} onPress={safeBack} hitSlop={12}>
          <Ionicons name="chevron-back" size={26} color={c.text} />
        </Pressable>
        <Text style={styles.headerTitle}>{t('profile.myShares')}</Text>
        <View style={styles.headerSpacer} />
      </View>

      <ScrollView contentContainerStyle={styles.scroll}>
        {isLoading ? (
          <View style={styles.center}>
            <ActivityIndicator color={c.primary} />
          </View>
        ) : !shares || shares.length === 0 ? (
          <Text style={styles.empty}>{t('shares.list.empty')}</Text>
        ) : (
          shares.map((s) => <ShareRow key={s.id} share={s} />)
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    header: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 12,
      paddingHorizontal: 16,
      paddingVertical: 10,
      borderBottomWidth: StyleSheet.hairlineWidth,
      borderBottomColor: c.border,
    },
    headerTitle: { flex: 1, fontFamily: fonts.display, fontSize: 20, fontWeight: '700', color: c.text },
    headerSpacer: { width: 26 },
    scroll: { padding: 16 },
    center: { paddingVertical: 48, alignItems: 'center' },
    empty: { fontSize: 15, color: c.muted, textAlign: 'center', paddingVertical: 48 },
  });
