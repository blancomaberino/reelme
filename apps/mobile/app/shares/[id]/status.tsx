import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useEffect, useMemo } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useRetryShare } from '@/api/hooks/useRetryShare';
import { useShareStatus } from '@/api/hooks/useShareStatus';
import { hasEditableExtraction, isRetryable, isTerminal, type ShareDetail, type ShareStatus } from '@/api/shares';
import { Button } from '@/components/button';
import { PendingVenues } from '@/components/share/pending-venues';
import { type MessageKey, useT } from '@/i18n';
import { fonts, type Palette, useColors } from '@/theme/colors';

// The pipeline stages, in order, with the label shown for each stepper row. The
// 4th step's label flips to "Published" once it lands (see terminalStepKey).
const STEPS: { status: ShareStatus; label: MessageKey }[] = [
  { status: 'pending', label: 'shares.step.pending' },
  { status: 'fetching', label: 'shares.step.fetching' },
  { status: 'analyzing', label: 'shares.step.analyzing' },
  { status: 'review', label: 'shares.step.review' },
];

const reachedIndex = (status: ShareStatus): number => {
  switch (status) {
    case 'pending':
      return 0;
    case 'fetching':
      return 1;
    case 'analyzing':
      return 2;
    default:
      return 3; // review / published / failed / rejected — the pipeline has stopped
  }
};

/**
 * The available action buttons for a terminal failure, keyed on `failure.code`.
 * Retry is emitted only when the API would honor it (isRetryable); link-account
 * is deferred (no mobile screen yet, T-015), so private posts route to manual.
 */
type FailAction = 'retry' | 'addManually' | 'aiSettings';
const FAIL_ACTIONS: Record<string, FailAction[]> = {
  fetch_unavailable: ['retry', 'addManually'],
  fetch_auth_required: ['addManually'],
  geocode_failed: ['addManually'],
  media_too_large: ['addManually'],
  ffmpeg_error: ['retry'],
  transcribe_error: ['retry'],
  cost_cap_exceeded: [],
  quota_exhausted: ['aiSettings'],
  invalid_model_output: ['retry', 'aiSettings'],
  resolve_conflict: ['retry'],
};

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
        <Pressable accessibilityRole="button" accessibilityLabel={t('place.back')} onPress={() => router.back()} hitSlop={12}>
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
            <Stepper status={share.status} styles={styles} c={c} t={t} />
            <Terminal share={share} shareId={shareId} styles={styles} c={c} t={t} />
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

function Stepper({
  status,
  styles,
  c,
  t,
}: {
  status: ShareStatus;
  styles: Styles;
  c: Palette;
  t: (k: MessageKey) => string;
}) {
  const reached = reachedIndex(status);
  const failed = status === 'failed' || status === 'rejected';
  const published = status === 'published';

  return (
    <View style={styles.stepper}>
      {STEPS.map((step, i) => {
        const isLast = i === STEPS.length - 1;
        const label = isLast && published ? t('shares.step.published') : t(step.label);
        // Node state: done (past, or a happy terminal), active (current & running),
        // error (terminal failure at the final node), or upcoming (dim).
        let node: 'done' | 'active' | 'error' | 'todo';
        if (i < reached) node = 'done';
        else if (i === reached) node = failed ? 'error' : published || status === 'review' ? 'done' : 'active';
        else node = 'todo';

        return (
          <View key={step.status} style={styles.stepRow}>
            <View style={styles.stepRail}>
              <View
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
              {!isLast ? <View style={[styles.connector, i < reached && styles.connectorDone]} /> : null}
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
  const actions = FAIL_ACTIONS[code] ?? ['retry'];

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
const KNOWN_FAIL_CODES = new Set([
  'fetch_unavailable', 'fetch_auth_required', 'geocode_failed', 'media_too_large', 'ffmpeg_error',
  'transcribe_error', 'cost_cap_exceeded', 'quota_exhausted', 'invalid_model_output', 'resolve_conflict',
]);
const failTitle = (code: string): MessageKey =>
  (KNOWN_FAIL_CODES.has(code) ? `shares.fail.${code}.title` : 'shares.fail.default.title') as MessageKey;
const failBody = (code: string): MessageKey =>
  (KNOWN_FAIL_CODES.has(code) ? `shares.fail.${code}.body` : 'shares.fail.default.body') as MessageKey;

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
