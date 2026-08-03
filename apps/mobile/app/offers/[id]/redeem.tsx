import { Ionicons } from '@expo/vector-icons';
import { useIsFocused } from '@react-navigation/native';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useIssueRedemption, useRedemption } from '@/api/hooks/useRedemptions';
import { codeState, formatRemaining, refusalReason, secondsRemaining } from '@/api/redemptions';
import { Button } from '@/components/button';
import { RedemptionCode } from '@/components/offer/redemption-code';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
import { useScreenBrightness } from '@/lib/use-screen-brightness';
import { fonts, type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * The diner's code (T-047, 05 screen #18).
 *
 * A three-state machine — `active → verified`, or `active → expired` — and the
 * transitions are what the screen is FOR: the diner is standing at a counter
 * while someone scans, and the screen must flip to "done" without them
 * refreshing it. That is why it polls, and why it stops the moment the answer
 * is settled.
 *
 * State is computed from the server's status AND the clock, never from status
 * alone: the expiry sweep runs on a schedule, so a lapsed code still reads
 * `issued` until it catches up. Showing that to a till is a customer being told
 * "no" at the counter.
 */
export default function RedeemScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const focused = useIsFocused();

  const params = useLocalSearchParams<{ id: string; shareId?: string; redemptionId?: string }>();
  const offerId = params.id;

  const issue = useIssueRedemption();
  const [redemptionId, setRedemptionId] = useState<string | null>(params.redemptionId ?? null);
  const { data: redemption, isLoading } = useRedemption(redemptionId, { poll: focused });

  // Raised while a live code is on screen, restored on blur. A dim phone in a
  // dim restaurant is the difference between a scan that works and staff giving
  // up and typing the code by hand.
  const state = redemption ? codeState(redemption) : null;
  useScreenBrightness(focused && state === 'active');

  const [remaining, setRemaining] = useState(0);

  useEffect(() => {
    if (!redemption || state !== 'active') return;

    // Ticked locally but measured from the SERVER's `expires_at` every second,
    // so a paused JS timer or a clock nudge cannot make the countdown lie.
    const tick = () => setRemaining(secondsRemaining(redemption));
    tick();
    const timer = setInterval(tick, 1000);

    return () => clearInterval(timer);
  }, [redemption, state]);

  const claim = () =>
    issue.mutate(
      { offerId, shareId: params.shareId ?? null },
      { onSuccess: (created) => setRedemptionId(created.id) },
    );

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader title={t('redeem.title')} />

      <ScrollView contentContainerStyle={styles.scroll}>
        {redemptionId === null ? (
          <View style={styles.centered}>
            <Ionicons name="ticket-outline" size={48} color={c.primary} />
            <Text style={styles.lead}>{t('redeem.beforeYouStart')}</Text>
            <Text style={styles.hint}>{t('redeem.oneCodeHint')}</Text>
            <Button
              title={t('redeem.cta')}
              onPress={claim}
              loading={issue.isPending}
              testID="redeem-cta"
            />
            {issue.isError ? (
              <Text style={styles.error} testID="redeem-error">
                {t(issueErrorKey(issue.error))}
              </Text>
            ) : null}
          </View>
        ) : isLoading || !redemption ? (
          <ActivityIndicator color={c.primary} style={styles.loading} accessibilityLabel={t('common.loading')} />
        ) : state === 'verified' ? (
          <View style={styles.centered} testID="redeem-verified">
            <View style={[styles.badge, { backgroundColor: c.greenSoft }]}>
              <Ionicons name="checkmark-circle" size={56} color={c.green} />
            </View>
            <Text style={styles.headline}>{t('redeem.verified')}</Text>
            <Text style={styles.hint}>{t('redeem.verifiedBody')}</Text>
            <Button title={t('common.close')} variant="secondary" onPress={() => router.back()} />
          </View>
        ) : state === 'active' ? (
          <View style={styles.centered} testID="redeem-active">
            {/* The QR is the primary path — a machine reads it — and the typed
                code is the fallback for a scanner that will not focus. */}
            <RedemptionCode payload={redemption.qr_payload ?? ''} code={redemption.code_display ?? ''} />

            <View style={styles.countdown}>
              <Ionicons name="time-outline" size={16} color={c.muted} />
              <Text style={styles.countdownText} testID="redeem-countdown">
                {t('redeem.expiresIn', { time: formatRemaining(remaining) })}
              </Text>
            </View>

            <Text style={styles.hint}>{t('redeem.showToStaff')}</Text>
          </View>
        ) : (
          <View style={styles.centered} testID="redeem-expired">
            <View style={[styles.badge, { backgroundColor: c.surface2 }]}>
              <Ionicons name="time-outline" size={56} color={c.ink2} />
            </View>
            <Text style={styles.headline}>{t('redeem.expired')}</Text>
            <Text style={styles.hint}>{t('redeem.expiredBody')}</Text>
            {/* A lapsed code is not a dead end — the offer may still be running,
                so the diner can take a fresh one rather than leaving. */}
            <Button
              title={t('redeem.getAnother')}
              onPress={() => {
                setRedemptionId(null);
                issue.reset();
              }}
              testID="redeem-again"
            />
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

/**
 * Each refusal gets its own advice.
 *
 * "Come back tomorrow" and "you already have a code" are different
 * instructions, and a single "could not redeem" teaches people to keep tapping
 * the button.
 */
function issueErrorKey(error: unknown): Parameters<ReturnType<typeof useT>>[0] {
  switch (refusalReason(error)) {
    case 'already_issued':
      return 'redeem.error.alreadyIssued';
    case 'user_quota_reached':
      return 'redeem.error.quota';
    case 'velocity_exceeded':
      return 'redeem.error.velocity';
    case 'cooldown':
      return 'redeem.error.cooldown';
    case 'self_dealing':
      return 'redeem.error.selfDealing';
    case 'offer_not_redeemable':
      return 'redeem.error.unavailable';
    default:
      return 'common.error.general';
  }
}

/** Big enough to read at a glance while someone waits. */
const BADGE = 96;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    scroll: { flexGrow: 1, padding: space.md },
    loading: { paddingVertical: space.xxl },
    centered: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: space.md, paddingVertical: space.lg },
    badge: { width: BADGE, height: BADGE, borderRadius: radius.pill, alignItems: 'center', justifyContent: 'center' },
    headline: { ...type.hero, fontFamily: fonts.display, color: c.text, textAlign: 'center' },
    lead: { ...type.title, color: c.text, textAlign: 'center' },
    hint: { ...type.body, color: c.muted, textAlign: 'center', paddingHorizontal: space.md },
    error: { ...type.body, color: c.danger, textAlign: 'center', paddingHorizontal: space.md },
    countdown: { flexDirection: 'row', alignItems: 'center', gap: space.xxs },
    countdownText: { ...type.bodyLg, color: c.ink2 },
  });
