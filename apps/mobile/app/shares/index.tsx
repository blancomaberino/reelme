import { Stack } from 'expo-router';
import { useMemo } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useShares } from '@/api/hooks/useShares';
import { ShareRow } from '@/components/share/share-row';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';

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
      <ScreenHeader title={t('profile.myShares')} divided />

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
    scroll: { padding: 16 },
    center: { paddingVertical: 48, alignItems: 'center' },
    empty: { fontSize: 15, color: c.muted, textAlign: 'center', paddingVertical: 48 },
  });
