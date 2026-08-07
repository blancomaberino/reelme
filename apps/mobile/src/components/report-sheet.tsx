import { Ionicons } from '@expo/vector-icons';
import { useMemo, useState } from 'react';
import {
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import {
  REPORT_REASONS,
  type ReportReason,
  type ReportableType,
  isAlreadyReported,
  useReport,
} from '@/api/hooks/useReport';
import { Button } from '@/components/button';
import { TextField } from '@/components/text-field';
import { type MessageKey, useT } from '@/i18n';
import { useSessionStore } from '@/stores/session';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

type Props = {
  visible: boolean;
  onClose: () => void;
  target: { type: ReportableType; id: string };
  /** What is being reported, shown back so nobody flags the wrong thing. */
  subject: string;
};

/**
 * Report content (T-049).
 *
 * One sheet for every reportable surface — place, share, profile — because
 * three near-identical sheets would drift, and the one that drifted would be
 * the one a store reviewer happened to open. Same bottom-sheet shape as the
 * menu/filter/delete sheets, so the most consequential dialog in the app is
 * not also the only unfamiliar one.
 *
 * A 409 is treated as SUCCESS: "you already reported this" means the flag is on
 * file, and telling someone their report failed would invite them to try again
 * against an endpoint that will keep refusing.
 */
export function ReportSheet({ visible, onClose, target, subject }: Props) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const report = useReport();
  const authed = useSessionStore((s) => s.status === 'authed');

  const [reason, setReason] = useState<ReportReason | null>(null);
  const [details, setDetails] = useState('');
  const [done, setDone] = useState(false);

  // Reset on the CLOSE transition, adjusting state during render rather than in
  // an effect (React's own guidance). The sheet stays mounted — only the
  // Modal's children unmount — so without this the next open would show the
  // previous report's reason and its confirmation.
  const [wasVisible, setWasVisible] = useState(visible);

  if (wasVisible !== visible) {
    setWasVisible(visible);

    if (!visible) {
      setReason(null);
      setDetails('');
      setDone(false);
      report.reset();
    }
  }

  const submit = () => {
    if (!reason) return;

    report.mutate(
      {
        reportable_type: target.type,
        reportable_id: target.id,
        reason,
        details: details.trim() || undefined,
      },
      {
        onSuccess: () => setDone(true),
        // Already reported IS the outcome the user wanted. Anything else is a
        // real failure and keeps the form open so the report is not lost.
        onError: (error) => isAlreadyReported(error) && setDone(true),
      },
    );
  };

  const failed = report.isError && !isAlreadyReported(report.error);

  return (
    <Modal visible={visible} animationType="slide" transparent onRequestClose={onClose}>
      <KeyboardAvoidingView
        style={styles.fill}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <Pressable
          style={styles.backdrop}
          onPress={onClose}
          accessibilityElementsHidden
          importantForAccessibility="no"
        />
        <SafeAreaView style={styles.sheet} edges={['bottom']}>
          <View style={styles.handle} />

          {done ? (
            <View style={styles.doneBlock} testID="report-done">
              <Ionicons name="checkmark-circle" size={28} color={c.green} />
              <Text style={styles.title}>{t('report.doneTitle')}</Text>
              <Text style={styles.body}>{t('report.doneBody')}</Text>
              <Button
                title={t('common.close')}
                variant="secondary"
                onPress={onClose}
                testID="report-close"
              />
            </View>
          ) : (
            <>
              <Text style={styles.title}>{t('report.title')}</Text>
              <Text style={styles.subject} numberOfLines={2}>
                {subject}
              </Text>

              {!authed ? (
                // Reachable from a public place page while signed out.
                <Text style={styles.body} testID="report-signed-out">
                  {t('report.signedOut')}
                </Text>
              ) : (
                <>
                  <ScrollView style={styles.reasons} keyboardShouldPersistTaps="handled">
                    {REPORT_REASONS.map((option) => (
                      <Pressable
                        key={option}
                        accessibilityRole="radio"
                        accessibilityState={{ selected: reason === option }}
                        accessibilityLabel={t(`report.reason.${option}` as MessageKey)}
                        onPress={() => setReason(option)}
                        style={({ pressed }) => [
                          styles.reason,
                          reason === option && styles.reasonOn,
                          pressed && styles.reasonPressed,
                        ]}
                        testID={`report-reason-${option}`}
                      >
                        <Ionicons
                          name={reason === option ? 'radio-button-on' : 'radio-button-off'}
                          size={20}
                          color={reason === option ? c.primary : c.muted}
                        />
                        <Text style={styles.reasonLabel}>
                          {t(`report.reason.${option}` as MessageKey)}
                        </Text>
                      </Pressable>
                    ))}
                  </ScrollView>

                  <TextField
                    label={t('report.detailsLabel')}
                    value={details}
                    onChangeText={setDetails}
                    multiline
                    maxLength={2000}
                    testID="report-details"
                  />

                  {failed ? (
                    <Text style={styles.error} testID="report-error">
                      {t('common.error.general')}
                    </Text>
                  ) : null}

                  <View style={styles.actions}>
                    <Button
                      title={t('common.cancel')}
                      variant="secondary"
                      onPress={onClose}
                      style={styles.action}
                      testID="report-cancel"
                    />
                    <Button
                      title={t('report.submit')}
                      variant="danger"
                      disabled={!reason}
                      loading={report.isPending}
                      onPress={submit}
                      style={styles.action}
                      testID="report-submit"
                    />
                  </View>
                </>
              )}
            </>
          )}
        </SafeAreaView>
      </KeyboardAvoidingView>
    </Modal>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    fill: { flex: 1 },
    backdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.35)' },
    sheet: {
      backgroundColor: c.background,
      borderTopLeftRadius: radius.lg,
      borderTopRightRadius: radius.lg,
      padding: space.md,
      gap: space.sm,
      maxHeight: '85%',
    },
    handle: {
      alignSelf: 'center',
      width: 40,
      height: 4,
      borderRadius: radius.pill,
      backgroundColor: c.border,
      marginBottom: space.xs,
    },
    title: { ...type.title, color: c.text },
    subject: { ...type.bodySm, color: c.muted },
    body: { ...type.body, color: c.ink2, lineHeight: 21 },
    reasons: { maxHeight: 260 },
    reason: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: space.sm,
      paddingVertical: space.sm,
      paddingHorizontal: space.sm,
      borderRadius: radius.md,
    },
    reasonOn: { backgroundColor: c.surface2 },
    reasonPressed: { opacity: 0.7 },
    reasonLabel: { ...type.body, flex: 1, color: c.text },
    error: { ...type.bodySm, color: c.danger },
    actions: { flexDirection: 'row', gap: space.sm, marginTop: space.xs },
    action: { flex: 1 },
    doneBlock: { alignItems: 'center', gap: space.sm, paddingVertical: space.md },
  });
