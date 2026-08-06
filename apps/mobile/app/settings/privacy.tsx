import { Ionicons } from '@expo/vector-icons';
import { Stack, router } from 'expo-router';
import { type ReactNode, useMemo } from 'react';
import {
  Alert,
  ScrollView,
  StyleSheet,
  type StyleProp,
  Text,
  View,
  type ViewStyle,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useDeleteAccount, useExportMyData } from '@/api/hooks/useGdpr';
import { Button } from '@/components/button';
import { ScreenHeader } from '@/components/screen-header';
import { type MessageKey, useT } from '@/i18n';
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

  // A disabled control must always say WHY, and the two reasons are not the
  // same sentence. Deriving the reason instead of testing the flag inline is
  // what stops the guest case from silently becoming a pair of dead buttons the
  // day T-050 flips the flag on — which is the very thing this screen exists to
  // avoid.
  const unavailable = !featureFlags.gdprSelfService ? 'pending' : !authed ? 'signedOut' : null;

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

        {unavailable ? (
          <IconNote
            icon={UNAVAILABLE_NOTE[unavailable].icon}
            tint={c.muted}
            title={t(UNAVAILABLE_NOTE[unavailable].title)}
            body={t(UNAVAILABLE_NOTE[unavailable].body)}
            boxed
            testID={`privacy-${unavailable}`}
          />
        ) : null}

        <RightCard
          icon="download-outline"
          tint={c.primary}
          badgeStyle={styles.badgeExport}
          title={t('privacy.export.title')}
          body={t('privacy.export.body')}
        >
          {exportData.isSuccess ? (
            // Stays put instead of firing a toast: "we emailed you a link, in a
            // few hours" is information the user needs after they look away.
            <IconNote
              icon="checkmark-circle"
              tint={c.green}
              title={t('privacy.export.doneTitle')}
              body={t('privacy.export.doneBody')}
              testID="privacy-export-done"
            />
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
        </RightCard>

        <RightCard
          icon="trash-outline"
          tint={c.danger}
          badgeStyle={styles.badgeDelete}
          title={t('privacy.delete.title')}
          body={t('privacy.delete.body')}
        >
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
        </RightCard>
      </ScrollView>
    </SafeAreaView>
  );
}

/**
 * Why the actions are off, as one lookup rather than the same ternary repeated
 * per element — adding a third reason should mean adding a row here, not
 * touching three JSX expressions and forgetting the fourth.
 */
const UNAVAILABLE_NOTE = {
  pending: { icon: 'time-outline', title: 'privacy.pending', body: 'privacy.pendingBody' },
  signedOut: {
    icon: 'lock-closed-outline',
    title: 'privacy.signedOut',
    body: 'privacy.signedOutBody',
  },
} as const satisfies Record<string, { icon: IconName; title: MessageKey; body: MessageKey }>;

type IconName = keyof typeof Ionicons.glyphMap;

/**
 * An icon beside a small title and body. The "not available" notice and the
 * export confirmation were byte-identical style sets doing this, so they are one
 * component — `boxed` is the only thing that ever differed between them.
 */
function IconNote({
  icon,
  tint,
  title,
  body,
  boxed = false,
  testID,
}: {
  icon: IconName;
  tint: string;
  title: string;
  body: string;
  boxed?: boolean;
  testID?: string;
}) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);

  return (
    <View style={[styles.note, boxed && styles.noteBoxed]} testID={testID}>
      <Ionicons name={icon} size={18} color={tint} />
      <View style={styles.noteText}>
        <Text style={styles.noteTitle}>{title}</Text>
        <Text style={styles.noteBody}>{body}</Text>
      </View>
    </View>
  );
}

/** One of the two rights: badge + heading, the explanation, then its action. */
function RightCard({
  icon,
  tint,
  badgeStyle,
  title,
  body,
  children,
}: {
  icon: IconName;
  tint: string;
  badgeStyle: StyleProp<ViewStyle>;
  title: string;
  body: string;
  children: ReactNode;
}) {
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);

  return (
    <View style={styles.card}>
      <View style={styles.cardHead}>
        <View style={[styles.badge, badgeStyle]}>
          <Ionicons name={icon} size={18} color={tint} />
        </View>
        <Text style={styles.cardTitle}>{title}</Text>
      </View>
      <Text style={styles.cardBody}>{body}</Text>
      {children}
    </View>
  );
}

const BADGE = 34;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    scroll: { padding: space.md, gap: space.md, paddingBottom: space.xl },
    intro: { ...type.body, color: c.ink2, lineHeight: 21 },

    // Top-aligned rather than nudged by a fraction of a token: `space.xxs / 2`
    // is exactly the arithmetic tokens.ts warns about — it keeps the pixels and
    // reintroduces the drift.
    note: { flexDirection: 'row', alignItems: 'flex-start', gap: space.sm },
    noteBoxed: { backgroundColor: c.surface2, borderRadius: radius.md, padding: space.sm },
    noteText: { flex: 1, gap: space.xxs },
    noteTitle: { ...type.bodySm, fontWeight: '700', color: c.text },
    noteBody: { ...type.bodySm, color: c.muted, lineHeight: 18 },

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
  });
