import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useEffect, useMemo } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useRetryShare } from '@/api/hooks/useRetryShare';
import { useShareStatus } from '@/api/hooks/useShareStatus';
import {
  type FailureCode,
  hasEditableExtraction,
  isRetryable,
  isTerminal,
  type ShareDetail,
  type ShareStatus,
} from '@/api/shares';
import { Button } from '@/components/button';
import { PendingVenues } from '@/components/share/pending-venues';
import { type MessageKey, useT } from '@/i18n';
import { safeBack } from '@/lib/nav';
import { fonts, type Palette, useColors } from '@/theme/colors';

// The pipeline stages, in order, with the label shown for each stepper row. The
// 4th step's label flips to "Published" once it lands (see terminalStepKey).
const STEPS: { status: ShareStatus; label: MessageKey }[] = [
  { status: 'pending', label: 'shares.step.pending' },
  { status: 'fetching', label: 'shares.step.fetching' },
  { status: 'analyzing', label: 'shares.step.analyzing' },
  { status: 'review', label: 'shares.step.review' },
];

// How far the pipeline got — the failed status's index in STEPS, clamping every
// stopped state (published/failed/rejected, none of which are stepper rows) to
// the last node. Deriving off STEPS keeps the stage order stated in one place.
const reachedIndex = (status: ShareStatus): number => {
  const i = STEPS.findIndex((s) => s.status === status);
  return i === -1 ? STEPS.length - 1 : i;
};

type FailAction = 'retry' | 'addManually' | 'aiSettings';

/**
 * Single source of truth for how each terminal failure is presented, keyed on
 * `failure.code`. `stopStep` is the STEPS index the pipeline halted at (fetch
 * failures at "fetching", model/transcribe at "analyzing", geocode/resolve at
 * "review") — drives the stepper's error marker so un-run stages never render as
 * done. `actions` are the buttons offered (retry only when the API honors it;
 * link-account is deferred per T-015, so private posts route to manual). Typing
 * this `Record<FailureCode, …>` forces an entry per code — a new failure can't be
 * half-wired — and its keys double as the "known code has dedicated copy" set.
 */
const FAILURE_TAXONOMY: Record<FailureCode, { actions: FailAction[]; stopStep: number }> = {
  fetch_unavailable: { actions: ['retry', 'addManually'], stopStep: 1 },
  fetch_auth_required: { actions: ['addManually'], stopStep: 1 },
  media_too_large: { actions: ['addManually'], stopStep: 1 },
  ffmpeg_error: { actions: ['retry'], stopStep: 1 },
  transcribe_error: { actions: ['retry'], stopStep: 2 },
  cost_cap_exceeded: { actions: [], stopStep: 2 },
  quota_exhausted: { actions: ['aiSettings'], stopStep: 2 },
  invalid_model_output: { actions: ['retry', 'aiSettings'], stopStep: 2 },
  ollama_unreachable: { actions: ['retry', 'aiSettings'], stopStep: 2 },
  geocode_failed: { actions: ['addManually'], stopStep: 3 },
  resolve_conflict: { actions: ['retry'], stopStep: 3 },
};

/** The taxonomy entry for a raw `failure.code`, or null for an unrecognized code. */
const failureEntry = (code: string | undefined): { actions: FailAction[]; stopStep: number } | null =>
  code && code in FAILURE_TAXONOMY ? FAILURE_TAXONOMY[code as FailureCode] : null;

export default function StatusScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const shareId = id ?? '';
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const { data: share, isLoading, isError } = useShareStatus(shareId || null);

  // An editable review forwards straight to the correction form — the status
  // screen only lingers for progress, published, and non-editable failures.
  useEffect(() => {
    if (share?.status === 'review' && hasEditableExtraction(share)) {
      router.replace({ pathname: '/shares/[id]/review', params: { id: shareId } });
    }
  }, [share, shareId]);

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.header}>
        <Pressable accessibilityRole="button" accessibilityLabel={t('place.back')} onPress={safeBack} hitSlop={12}>
          <Ionicons name="chevron-back" size={26} color={c.text} />
        </Pressable>
        <Text style={styles.headerTitle}>{t('shares.detail.title')}</Text>
        <View style={styles.headerSpacer} />
      </View>

      <ScrollView contentContainerStyle={styles.scroll}>
        {isLoading ? (
          <View style={styles.center}>
            <ActivityIndicator color={c.primary} />
          </View>
        ) : isError || !share ? (
          <Text style={styles.notFound}>{t('shares.notFound')}</Text>
        ) : (
          <>
            <Stepper share={share} styles={styles} c={c} t={t} />
            <Terminal share={share} shareId={shareId} styles={styles} c={c} t={t} />
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

function Stepper({
  share,
  styles,
  c,
  t,
}: {
  share: ShareDetail;
  styles: Styles;
  c: Palette;
  t: (k: MessageKey) => string;
}) {
  const { status } = share;
  const published = status === 'published';
  // A failure is a failed/rejected share OR a review the user can't correct (a
  // fetch failure with no extraction). Those stop MID-pipeline, so the error node
  // sits where they stopped and later stages stay "todo" — never a green check
  // above the red failure card. Editable reviews / in-progress use reachedIndex.
  const failed = status === 'failed' || status === 'rejected' || (status === 'review' && !hasEditableExtraction(share));
  const errorIdx = failed ? (failureEntry(share.failure?.code)?.stopStep ?? STEPS.length - 1) : -1;
  const reached = reachedIndex(status);
  // How far the rail is "complete" — up to the error node, else the reached node.
  const filledTo = failed ? errorIdx : reached;

  return (
    <View style={styles.stepper}>
      {STEPS.map((step, i) => {
        const isLast = i === STEPS.length - 1;
        const label = isLast && published ? t('shares.step.published') : t(step.label);
        // Node state: done (past a completed stage), error (the stage it failed
        // at), active (current & running), or upcoming (dim).
        let node: 'done' | 'active' | 'error' | 'todo';
        if (failed) {
          node = i < errorIdx ? 'done' : i === errorIdx ? 'error' : 'todo';
        } else if (i < reached) {
          node = 'done';
        } else if (i === reached) {
          node = published || status === 'review' ? 'done' : 'active';
        } else {
          node = 'todo';
        }

        return (
          <View key={step.status} style={styles.stepRow}>
            <View style={styles.stepRail}>
              <View
                testID={`step-${step.status}-${node}`}
                style={[
                  styles.node,
                  node === 'done' && styles.nodeDone,
                  node === 'error' && styles.nodeError,
                  node === 'active' && styles.nodeActive,
                ]}
              >
                {node === 'done' ? (
                  <Ionicons name="checkmark" size={14} color={c.onPrimary} />
                ) : node === 'error' ? (
                  <Ionicons name="close" size={14} color={c.onPrimary} />
                ) : node === 'active' ? (
                  <ActivityIndicator size="small" color={c.primary} />
                ) : (
                  <View style={styles.nodeDot} />
                )}
              </View>
              {!isLast ? <View style={[styles.connector, i < filledTo && styles.connectorDone]} /> : null}
            </View>
            <Text style={[styles.stepLabel, node === 'todo' && styles.stepLabelTodo]}>{label}</Text>
          </View>
        );
      })}
    </View>
  );
}

function Terminal({
  share,
  shareId,
  styles,
  c,
  t,
}: {
  share: ShareDetail;
  shareId: string;
  styles: Styles;
  c: Palette;
  t: (k: MessageKey, p?: Record<string, string | number>) => string;
}) {
  const retry = useRetryShare(shareId);

  if (!isTerminal(share.status)) {
    return <Text style={styles.caption}>{t('share.processing')}</Text>;
  }

  if (share.status === 'published') {
    const places = share.places?.length ? share.places : share.place ? [share.place] : [];
    return (
      <View style={styles.terminal}>
        <View style={[styles.badge, styles.badgeOk]}>
          <Ionicons name="checkmark" size={26} color={c.green} />
        </View>
        <Text style={styles.terminalTitle}>{t('share.published.title')}</Text>
        {places.map((p) => (
          <Pressable
            key={p.id}
            accessibilityRole="button"
            accessibilityLabel={p.name}
            onPress={() => router.push({ pathname: '/place/[slug]', params: { slug: p.id } })}
            style={({ pressed }) => [styles.placeRow, pressed && styles.pressed]}
          >
            <Text style={styles.placeName} numberOfLines={1}>
              {p.name}
            </Text>
            <Ionicons name="chevron-forward" size={18} color={c.muted} />
          </Pressable>
        ))}
        {places[0] ? (
          <Button
            title={t('shares.published.viewOnMap')}
            onPress={() => router.push({ pathname: '/place/[slug]', params: { slug: places[0].id } })}
          />
        ) : null}
        {(share.pending_place_count ?? 0) > 0 ? (
          <PendingVenues shareId={shareId} venues={share.pending_places ?? []} />
        ) : null}
        <Pressable accessibilityRole="button" onPress={() => router.replace('/(main)/share')} hitSlop={8}>
          <Text style={styles.link}>{t('share.another')}</Text>
        </Pressable>
      </View>
    );
  }

  // review (non-editable — a fetch failure) or failed / rejected → the failure card.
  const code = share.failure?.code ?? 'default';
  // Unknown codes (and a `rejected` share with null failure) must still offer an
  // escape: retry only if the API would honor it, always "add by hand" — never a
  // card whose only button is a disabled Retry.
  const actions = failureEntry(code)?.actions ?? (isRetryable(share) ? ['retry', 'addManually'] : ['addManually']);

  const runAction = (a: FailAction) => {
    if (a === 'retry') retry.mutate();
    else if (a === 'addManually') router.replace('/(main)/share');
    else if (a === 'aiSettings') router.push('/settings');
  };

  return (
    <View style={styles.terminal}>
      <View style={[styles.badge, styles.badgeErr]}>
        <Ionicons name="alert" size={26} color={c.danger} />
      </View>
      <Text style={styles.terminalTitle}>{t(failTitle(code))}</Text>
      <Text style={styles.failBody}>{t(failBody(code))}</Text>
      <View style={styles.actions}>
        {actions.map((a) =>
          a === 'retry' ? (
            <Button key={a} title={t('share.retry')} onPress={() => runAction(a)} loading={retry.isPending} disabled={!isRetryable(share)} />
          ) : (
            <Button
              key={a}
              title={t(a === 'addManually' ? 'shares.action.addManually' : 'shares.action.aiSettings')}
              variant="secondary"
              onPress={() => runAction(a)}
            />
          ),
        )}
      </View>
    </View>
  );
}

// The failure copy keys are static strings; fall back to the generic pair when a
// code has no dedicated copy (unlisted pipeline reasons).
// A code in the taxonomy has dedicated copy; anything else uses the generic pair.
const failTitle = (code: string): MessageKey =>
  (code in FAILURE_TAXONOMY ? `shares.fail.${code}.title` : 'shares.fail.default.title') as MessageKey;
const failBody = (code: string): MessageKey =>
  (code in FAILURE_TAXONOMY ? `shares.fail.${code}.body` : 'shares.fail.default.body') as MessageKey;

type Styles = ReturnType<typeof makeStyles>;

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
    scroll: { padding: 24, gap: 24 },
    center: { paddingVertical: 48, alignItems: 'center' },
    notFound: { fontSize: 15, color: c.muted, textAlign: 'center', paddingVertical: 48 },
    caption: { fontSize: 15, color: c.muted, textAlign: 'center' },

    stepper: { gap: 0, paddingLeft: 4 },
    stepRow: { flexDirection: 'row', gap: 14 },
    stepRail: { alignItems: 'center', width: 28 },
    node: {
      width: 28,
      height: 28,
      borderRadius: 14,
      alignItems: 'center',
      justifyContent: 'center',
      borderWidth: 1.5,
      borderColor: c.line2,
      backgroundColor: c.surface,
    },
    nodeDone: { backgroundColor: c.primary, borderColor: c.primary },
    nodeError: { backgroundColor: c.danger, borderColor: c.danger },
    nodeActive: { borderColor: c.primary },
    nodeDot: { width: 8, height: 8, borderRadius: 4, backgroundColor: c.line2 },
    connector: { width: 2, flex: 1, minHeight: 22, backgroundColor: c.line2, marginVertical: 2 },
    connectorDone: { backgroundColor: c.primary },
    stepLabel: { flex: 1, fontSize: 16, fontWeight: '600', color: c.text, paddingTop: 3, paddingBottom: 22 },
    stepLabelTodo: { color: c.muted, fontWeight: '500' },

    terminal: { alignItems: 'center', gap: 12 },
    terminalTitle: { fontFamily: fonts.display, fontSize: 22, fontWeight: '700', color: c.text, textAlign: 'center' },
    failBody: { fontSize: 15, color: c.muted, textAlign: 'center', lineHeight: 21 },
    badge: { width: 56, height: 56, borderRadius: 28, alignItems: 'center', justifyContent: 'center' },
    badgeOk: { backgroundColor: c.greenSoft },
    badgeErr: { backgroundColor: c.dangerSoft },
    actions: { alignSelf: 'stretch', gap: 10, marginTop: 4 },
    placeRow: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'space-between',
      alignSelf: 'stretch',
      gap: 12,
      paddingVertical: 14,
      paddingHorizontal: 16,
      borderRadius: 14,
      backgroundColor: c.surface,
      borderWidth: 1,
      borderColor: c.border,
    },
    placeName: { flex: 1, fontSize: 16, fontWeight: '700', color: c.text },
    pressed: { opacity: 0.6 },
    link: { color: c.primary, fontSize: 15, fontWeight: '700', marginTop: 4 },
  });
