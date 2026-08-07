import { Stack, router } from 'expo-router';
import { useMemo } from 'react';
import { ActivityIndicator, Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { type BlockedUser, useBlocks, useUnblockUser } from '@/api/hooks/useBlocks';
import { Button } from '@/components/button';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * Blocked accounts (T-054, IR-6 / Apple Guideline 1.2).
 *
 * The screen exists so a block is REVERSIBLE. A blocked profile is a 404 for
 * the person who blocked it — deliberately, so they never see that account
 * again — which means there is no route back to it from anywhere else in the
 * app. Without this list a block would be permanent by accident, and a block
 * you cannot undo is a worse product than no block at all.
 */
export default function BlockedAccountsScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { data, isLoading, isError } = useBlocks();
  const unblock = useUnblockUser();

  const confirmUnblock = (user: BlockedUser) =>
    Alert.alert(
      t('block.unblockConfirmTitle', { name: user.username }),
      // Says what unblocking does NOT do. People expect a follow to come back
      // and it does not — the edge was severed, not paused.
      t('block.unblockConfirmBody'),
      [
        { text: t('common.cancel'), style: 'cancel' },
        {
          text: t('block.unblock'),
          onPress: () =>
            unblock.mutate(user.username, {
              // A failed DELETE leaves the account blocked. Without this the
              // row simply stays put and the user cannot tell whether the tap
              // registered — so they try again, or assume it worked and it did
              // not. Silence is the worst of the three outcomes.
              onError: () => Alert.alert(t('block.unblockFailedTitle'), t('block.failedBody')),
            }),
        },
      ],
    );

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader
        title={t('block.listTitle')}
        onBack={() => (router.canGoBack() ? router.back() : router.replace('/settings'))}
      />

      <ScrollView contentContainerStyle={styles.scroll}>
        <Text style={styles.subtitle}>{t('block.listSubtitle')}</Text>

        {isLoading ? (
          <ActivityIndicator color={c.primary} style={styles.spinner} />
        ) : isError ? (
          <Text style={styles.empty} testID="blocked-error">
            {t('block.failedBody')}
          </Text>
        ) : (data?.length ?? 0) === 0 ? (
          <Text style={styles.empty} testID="blocked-empty">
            {t('block.listEmpty')}
          </Text>
        ) : (
          <View style={styles.list}>
            {data?.map((user) => (
              <View key={user.id} style={styles.row} testID={`blocked-${user.username}`}>
                <View style={styles.identity}>
                  <Text style={styles.handle} numberOfLines={1}>
                    @{user.username}
                  </Text>
                  {user.name ? (
                    <Text style={styles.name} numberOfLines={1}>
                      {user.name}
                    </Text>
                  ) : null}
                </View>
                {/* No tap-through to the profile: it is a 404 for this viewer,
                    so the row would navigate straight into an error screen. */}
                <Button
                  title={t('block.unblock')}
                  variant="secondary"
                  size="sm"
                  onPress={() => confirmUnblock(user)}
                  loading={unblock.isPending && unblock.variables === user.username}
                />
              </View>
            ))}
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    scroll: { padding: space.lg, gap: space.md },
    subtitle: { ...type.body, color: c.muted, lineHeight: 21 },
    spinner: { marginTop: space.xl },
    empty: { ...type.body, color: c.muted, marginTop: space.lg, textAlign: 'center' },
    list: { gap: space.xs },
    row: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: space.sm,
      paddingVertical: space.sm,
      paddingHorizontal: space.md,
      borderRadius: radius.lg,
      backgroundColor: c.surface,
      borderWidth: 1,
      borderColor: c.border,
    },
    identity: { flex: 1, gap: space.xxs },
    handle: { ...type.bodyLg, color: c.text },
    name: { ...type.bodySm, color: c.muted },
  });
