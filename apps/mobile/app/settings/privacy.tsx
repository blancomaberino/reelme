import { Ionicons } from '@expo/vector-icons';
import { Stack, router } from 'expo-router';
import { useMemo } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useDeleteAccount, useExportMyData } from '@/api/hooks/useGdpr';
import { Button } from '@/components/button';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
import { featureFlags } from '@/lib/feature-flags';
import { useSessionStore } from '@/stores/session';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * Privacy & data — the two GDPR rights (T-039, 05 screen #16).
 *
 * This screen ships BEFORE the endpoints it calls (T-050, M5), and that is a
 * deliberate call rather than an oversight. The alternative considered and
 * rejected was to ship nothing until M5: an app that stores your shares, your
 * saved places and your reviews and says nothing anywhere about getting them
 * back or getting rid of them is not more honest for staying quiet — it is just
 * quieter. So the explanation ships now and the buttons are visibly, plainly
 * marked as not working yet.
 *
 * What must NOT happen is a button that looks live and does nothing, so the
 * disabled state is stated twice: once as a notice above both cards, and once in
 * the controls themselves.
 */
export default function PrivacyScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const authed = useSessionStore((s) => s.status === 'authed');
  const exportData = useExportMyData();
  const deleteAccount = useDeleteAccount();

  // Both conditions are real gates, not belt-and-braces: the flag is the M5
  // switch, and `authed` covers someone arriving on a deep link while signed
  // out, where "delete my account" has no account to mean.
  const actionsEnabled = featureFlags.gdprSelfService && authed;

  const onExport = () => {
    exportData.mutate(undefined, {
      onError: () => Alert.alert(t('common.error.general')),
    });
  };

  const onDelete = () => {
    Alert.alert(t('privacy.delete.confirmTitle'), t('privacy.delete.confirmBody'), [
      { text: t('common.cancel'), style: 'cancel' },
      {
        text: t('privacy.delete.confirmCta'),
        style: 'destructive',
        onPress: () =>
          deleteAccount.mutate(undefined, {
            // The hook has already wiped the local session by the time this
            // runs, so the only thing left is to leave a screen that now
            // belongs to nobody.
            onSuccess: () => router.replace('/(auth)/welcome'),
            onError: () =>
              Alert.alert(t('privacy.delete.failedTitle'), t('common.error.general')),
          }),
      },
    ]);
  };

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader title={t('privacy.title')} divided />

      <ScrollView contentContainerStyle={styles.scroll}>
        <Text style={styles.intro}>{t('privacy.intro')}</Text>

        {!featureFlags.gdprSelfService ? (
          <View style={styles.notice} testID="privacy-pending">
            <Ionicons name="time-outline" size={18} color={c.muted} style={styles.noticeIcon} />
            <View style={styles.noticeText}>
              <Text style={styles.noticeTitle}>{t('privacy.pending')}</Text>
              <Text style={styles.noticeBody}>{t('privacy.pendingBody')}</Text>
            </View>
          </View>
        ) : null}

        <View style={styles.card}>
          <View style={styles.cardHead}>
            <View style={[styles.badge, styles.badgeExport]}>
              <Ionicons name="download-outline" size={18} color={c.primary} />
            </View>
            <Text style={styles.cardTitle}>{t('privacy.export.title')}</Text>
          </View>
          <Text style={styles.cardBody}>{t('privacy.export.body')}</Text>

          {exportData.isSuccess ? (
            // Stays put instead of firing a toast: "we emailed you a link, in a
            // few hours" is information the user needs after they look away.
            <View style={styles.done} testID="privacy-export-done">
              <Ionicons name="checkmark-circle" size={18} color={c.green} />
              <View style={styles.doneText}>
                <Text style={styles.doneTitle}>{t('privacy.export.doneTitle')}</Text>
                <Text style={styles.doneBody}>{t('privacy.export.doneBody')}</Text>
              </View>
            </View>
          ) : (
            <Button
              title={t('privacy.export.action')}
              variant="secondary"
              size="sm"
              disabled={!actionsEnabled}
              loading={exportData.isPending}
              onPress={onExport}
              style={styles.action}
              testID="privacy-export-action"
            />
          )}
        </View>

        <View style={styles.card}>
          <View style={styles.cardHead}>
            <View style={[styles.badge, styles.badgeDelete]}>
              <Ionicons name="trash-outline" size={18} color={c.danger} />
            </View>
            <Text style={styles.cardTitle}>{t('privacy.delete.title')}</Text>
          </View>
          <Text style={styles.cardBody}>{t('privacy.delete.body')}</Text>
          <Button
            title={t('privacy.delete.action')}
            variant="danger"
            size="sm"
            disabled={!actionsEnabled}
            loading={deleteAccount.isPending}
            onPress={onDelete}
            style={styles.action}
            testID="privacy-delete-action"
          />
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const BADGE = 34;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    scroll: { padding: space.md, gap: space.md, paddingBottom: space.xl },
    intro: { ...type.body, color: c.ink2, lineHeight: 21 },

    notice: {
      flexDirection: 'row',
      gap: space.sm,
      backgroundColor: c.surface2,
      borderRadius: radius.md,
      padding: space.sm,
    },
    // Optical alignment with the first line of the title, which a plain
    // `alignItems: flex-start` misses by a couple of points.
    noticeIcon: { marginTop: space.xxs / 2 },
    noticeText: { flex: 1, gap: space.xxs },
    noticeTitle: { ...type.bodySm, fontWeight: '700', color: c.text },
    noticeBody: { ...type.bodySm, color: c.muted, lineHeight: 18 },

    card: {
      backgroundColor: c.surface,
      borderRadius: radius.lg,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      padding: space.md,
      gap: space.sm,
    },
    cardHead: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
    badge: {
      width: BADGE,
      height: BADGE,
      borderRadius: radius.pill,
      alignItems: 'center',
      justifyContent: 'center',
    },
    // One accent family per card — terracotta for the benign action, danger for
    // the irreversible one. Borrowing the teal here would put two accents in one
    // card and make the pair read as decoration rather than as a signal.
    badgeExport: { backgroundColor: c.primarySoft },
    badgeDelete: { backgroundColor: c.dangerSoft },
    // Sized to its label: a full-bleed destructive button inside a card is a
    // bigger target than the sentence above it warrants.
    action: { alignSelf: 'flex-start', marginTop: space.xxs },
    cardTitle: { ...type.bodyLg, flex: 1, color: c.text },
    cardBody: { ...type.body, color: c.ink2, lineHeight: 21 },

    done: { flexDirection: 'row', gap: space.xs, alignItems: 'flex-start' },
    doneText: { flex: 1, gap: space.xxs },
    doneTitle: { ...type.bodySm, fontWeight: '700', color: c.text },
    doneBody: { ...type.bodySm, color: c.muted, lineHeight: 18 },
  });
