import { Ionicons } from '@expo/vector-icons';
import { Stack, router } from 'expo-router';
import { type ReactNode, useMemo, useState } from 'react';
import {
  Alert,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
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
import { TextField } from '@/components/text-field';
import { type MessageKey, useT } from '@/i18n';
import { featureFlags } from '@/lib/feature-flags';
import { useSessionStore } from '@/stores/session';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * Privacy & data — the two GDPR rights (T-039 screen, T-050 endpoints, 05 #16).
 *
 * Shipped in T-039 with both actions visibly disabled, because the endpoints
 * did not exist yet and a button that looks live and does nothing is worse than
 * one that admits it. T-050 built them, so the flag is on and the buttons work.
 * The disabled path stays: `authed` is still a real gate, and the flag is still
 * the switch that turns this off if the backend has to be rolled back.
 *
 * Deletion asks the user to TYPE a word rather than tap a destructive alert.
 * The tap is the wrong shape of gesture for this: a destructive alert button is
 * one slip away from the cancel next to it, and account deletion is the single
 * action in the app where the cost of a slip is everything the person has.
 * Typing cannot be done by accident.
 */
export default function PrivacyScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const authed = useSessionStore((s) => s.status === 'authed');
  const exportData = useExportMyData();
  const deleteAccount = useDeleteAccount();
  const [confirming, setConfirming] = useState(false);

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
    deleteAccount.mutate(undefined, {
      // The hook has already wiped the local session by the time this runs, so
      // the only thing left is to leave a screen that now belongs to nobody.
      onSuccess: () => {
        setConfirming(false);
        router.replace('/(auth)/welcome');
      },
      onError: () => {
        setConfirming(false);
        Alert.alert(t('privacy.delete.failedTitle'), t('common.error.general'));
      },
    });
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
            onPress={() => setConfirming(true)}
            style={styles.action}
            testID="privacy-delete-action"
          />
        </RightCard>
      </ScrollView>

      <DeleteConfirmSheet
        visible={confirming}
        pending={deleteAccount.isPending}
        onCancel={() => setConfirming(false)}
        onConfirm={onDelete}
      />
    </SafeAreaView>
  );
}

/**
 * The grace period the API actually applies (`GDPR_PURGE_GRACE_DAYS`, default
 * 14). Stated here as a named constant rather than baked into the sentence,
 * because that sentence is the most legally consequential one in the app: if
 * the server window ever changes, this is the single line to change, and
 * `AccountDeletionTest` pins the API default against it so the two cannot
 * drift silently.
 */
const DELETION_GRACE_DAYS = 14;

/**
 * The typed confirmation. Nothing here is reachable by a mis-tap: the button
 * stays disabled until the word is spelled out, so the gesture that deletes an
 * account is one the user had to compose.
 *
 * The grace period is stated as the LAST line, where it reads as reassurance
 * rather than as a reason to be casual — it is a safety net, not a preview.
 */
function DeleteConfirmSheet({
  visible,
  pending,
  onCancel,
  onConfirm,
}: {
  visible: boolean;
  pending: boolean;
  onCancel: () => void;
  onConfirm: () => void;
}) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const [typed, setTyped] = useState('');

  const word = t('privacy.delete.typeWord');
  // Case- and whitespace-tolerant: the point is deliberate intent, not a typing
  // test, and an on-screen keyboard that auto-capitalises would otherwise fail
  // people for something they did not do.
  //
  // toUpperCase, NOT toLocaleUpperCase: the latter uses the DEVICE locale,
  // independent of the app's language, and on a Turkish or Azerbaijani device
  // 'eliminar' uppercases to 'ELİMİNAR' — permanently unmatchable. That is a
  // hard lockout on the one flow Apple requires us to offer. Both words are
  // ASCII, so locale-aware casing buys nothing here.
  const matches = typed.trim().toUpperCase() === word.toUpperCase();

  // Reset on the CLOSE transition, not inside a handler. The sheet stays mounted
  // (only the Modal's children unmount), so `typed` survives — and cancel is not
  // the only way out: success and the error alert both clear `confirming`
  // directly. Leaving the word behind after a FAILED delete would make the next
  // open a one-tap account deletion, which is the exact thing typing it out
  // exists to prevent.
  //
  // Adjusted during render rather than in an effect: this is state derived from
  // a prop change, React documents exactly this pattern for it, and an effect
  // would be a second render pass for something already known here.
  const [wasVisible, setWasVisible] = useState(visible);

  if (wasVisible !== visible) {
    setWasVisible(visible);

    if (!visible) setTyped('');
  }

  const close = onCancel;

  return (
    <Modal visible={visible} animationType="slide" transparent onRequestClose={close}>
      <KeyboardAvoidingView
        style={styles.fill}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        {/* Unlabelled deliberately: a labelled full-bleed backdrop becomes
            VoiceOver's FIRST stop and announces "Cancel" before the question it
            is asking — and there is a real Cancel button right below. */}
        <Pressable
          style={styles.backdrop}
          onPress={close}
          accessibilityElementsHidden
          importantForAccessibility="no"
        />
        <SafeAreaView style={styles.sheet} edges={['bottom']}>
          <View style={styles.handle} />

          <View style={[styles.badge, styles.badgeDelete]}>
            <Ionicons name="warning-outline" size={18} color={c.danger} />
          </View>

          <Text style={styles.sheetTitle}>{t('privacy.delete.confirmTitle')}</Text>
          <Text style={styles.sheetBody}>{t('privacy.delete.confirmBody')}</Text>

          <TextField
            label={t('privacy.delete.typePrompt', { word })}
            value={typed}
            onChangeText={setTyped}
            autoCapitalize="characters"
            autoCorrect={false}
            returnKeyType="done"
            // No placeholder. Putting the word itself in one makes an empty
            // field read as already filled — seen on the first device run,
            // sitting above a disabled "delete everything" with no visible
            // reason why.
            testID="privacy-delete-confirm-input"
          />

            <Text style={styles.sheetFine}>
            {t('privacy.delete.graceNote', { days: DELETION_GRACE_DAYS })}
          </Text>

          <View style={styles.sheetActions}>
            <Button
              title={t('common.cancel')}
              variant="secondary"
              onPress={close}
              style={styles.sheetButton}
              testID="privacy-delete-cancel"
            />
            <Button
              title={t('privacy.delete.confirmCta')}
              variant="danger"
              disabled={!matches}
              loading={pending}
              onPress={onConfirm}
              style={styles.sheetButton}
              testID="privacy-delete-confirm"
            />
          </View>
        </SafeAreaView>
      </KeyboardAvoidingView>
    </Modal>
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

    // Confirmation sheet. Same bottom-sheet shape as MenuSheet/FilterSheet so
    // the most consequential dialog in the app is not also the only unfamiliar
    // one — a novel presentation here would read as an interstitial, not a
    // decision.
    // The KeyboardAvoidingView wrapper needs its own flex so the sheet is
    // pushed clear of the keyboard: the confirm field sits directly above both
    // buttons, and without this the keyboard covers the decision itself. The
    // sibling filter-sheet solves the same problem the same way.
    fill: { flex: 1 },
    backdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.35)' },
    sheet: {
      backgroundColor: c.background,
      borderTopLeftRadius: radius.lg,
      borderTopRightRadius: radius.lg,
      padding: space.md,
      gap: space.sm,
    },
    handle: {
      alignSelf: 'center',
      width: 40,
      height: 4,
      borderRadius: radius.pill,
      backgroundColor: c.border,
      marginBottom: space.xs,
    },
    sheetTitle: { ...type.title, color: c.text },
    sheetBody: { ...type.body, color: c.ink2, lineHeight: 21 },
    sheetFine: { ...type.bodySm, color: c.muted, lineHeight: 18 },
    sheetActions: { flexDirection: 'row', gap: space.sm, marginTop: space.xs },
    sheetButton: { flex: 1 },
  });
