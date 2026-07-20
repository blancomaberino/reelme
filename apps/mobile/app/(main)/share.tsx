import { Ionicons } from '@expo/vector-icons';
import { router, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Keyboard, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useCreateShare } from '@/api/hooks/useCreateShare';
import { useRetryShare } from '@/api/hooks/useRetryShare';
import { useShares } from '@/api/hooks/useShares';
import { useShareStatus } from '@/api/hooks/useShareStatus';
import {
  isTerminal,
  platformFromUrl,
  type ShareDetail,
  type SharePlatform,
  type ShareStatus,
} from '@/api/shares';
import { Button } from '@/components/button';
import { SaveToListSheet } from '@/components/place/save-to-list';
import { PendingVenues } from '@/components/share/pending-venues';
import { TextField } from '@/components/text-field';
import { type MessageKey, useT } from '@/i18n';
import { useUiStore } from '@/stores/ui';
import { fonts, type Palette, useColors } from '@/theme/colors';

/** Brand names + Ionicons glyphs for the platform badge (client-side hint). */
const PLATFORM_BADGE: Record<SharePlatform, { label: string; icon: keyof typeof Ionicons.glyphMap }> = {
  instagram: { label: 'Instagram', icon: 'logo-instagram' },
  tiktok: { label: 'TikTok', icon: 'logo-tiktok' },
  x: { label: 'X', icon: 'logo-twitter' },
  youtube: { label: 'YouTube', icon: 'logo-youtube' },
};

const STAGE_KEY: Partial<Record<ShareStatus, MessageKey>> = {
  pending: 'share.stage.pending',
  fetching: 'share.stage.fetching',
  analyzing: 'share.stage.analyzing',
};

export default function ShareScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const [url, setUrl] = useState('');
  const [caption, setCaption] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [shareId, setShareId] = useState<string | null>(null);
  // True when the API replayed an existing share (re-shared post) — drives the
  // friendly "you already added this one" note instead of a fresh-pin flow.
  const [replay, setReplay] = useState(false);

  const create = useCreateShare();
  const { data: share } = useShareStatus(shareId);

  const platform = useMemo(() => (url.trim() ? platformFromUrl(url) : null), [url]);

  const doSubmit = useCallback(
    (rawUrl: string, rawCaption: string, via: 'paste_url' | 'share_sheet' = 'paste_url') => {
      const u = rawUrl.trim();
      const cap = rawCaption.trim();
      if (!u && !cap) {
        setError(t('share.needInput'));
        return;
      }
      setError(null);
      Keyboard.dismiss();
      create.mutate(
        { url: u, caption: cap, sharedVia: via },
        {
          onSuccess: (s) => {
            setShareId(s.id);
            setReplay(s.idempotentReplay);
          },
          onError: () => setError(t('share.submitError')),
        },
      );
    },
    [create, t],
  );

  const submit = useCallback(() => doSubmit(url, caption), [doSubmit, url, caption]);

  const reset = useCallback(() => {
    setShareId(null);
    setReplay(false);
    setUrl('');
    setCaption('');
    setError(null);
  }, []);

  // A link/text shared in from another app (Instagram, Safari…) via the share
  // sheet: prefill and auto-submit once. The payload is staged in `useUiStore`
  // by the root ShareIntentRedirect so it survives the sign-in redirect; deep
  // links (Maestro/CI) still pass it as route params, read as a fallback. A
  // non-URL payload goes to the caption; `handled` guards re-firing on
  // re-render / re-focus, and the staged share is cleared once consumed.
  const { sharedUrl, sharedText } = useLocalSearchParams<{ sharedUrl?: string; sharedText?: string }>();
  const staged = useUiStore((s) => s.pendingShare);
  const handled = useRef('');
  useEffect(() => {
    const u = (staged?.url ?? sharedUrl ?? '').trim();
    const txt = (staged?.text ?? sharedText ?? '').trim();
    if (staged) useUiStore.getState().setPendingShare(null);
    const payload = u || txt;
    if (!payload || handled.current === payload) return;
    handled.current = payload;
    const looksUrl = /^https?:\/\//i.test(txt);
    const finalUrl = u || (looksUrl ? txt : '');
    const finalCap = finalUrl ? '' : txt;
    setUrl(finalUrl);
    setCaption(finalCap);
    doSubmit(finalUrl, finalCap, 'share_sheet');
  }, [staged, sharedUrl, sharedText, doSubmit]);

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
        <Text style={styles.title}>{t('share.title')}</Text>
        <Text style={styles.subtitle}>{t('share.subtitle')}</Text>

        {shareId ? (
          <ShareProgress share={share} status={share?.status} onReset={reset} replay={replay} c={c} styles={styles} t={t} />
        ) : (
          <View style={styles.form}>
            <TextField
              label={t('share.urlLabel')}
              value={url}
              onChangeText={setUrl}
              placeholder={t('share.urlPlaceholder')}
              keyboardType="url"
              autoCorrect={false}
              autoCapitalize="none"
              returnKeyType="next"
            />
            {platform ? (
              <View
                accessibilityRole="text"
                accessibilityLabel={t('share.platformDetected', { platform: PLATFORM_BADGE[platform].label })}
                style={styles.platformBadge}
              >
                <Ionicons name={PLATFORM_BADGE[platform].icon} size={14} color={c.primary} />
                <Text style={styles.platformBadgeText}>{PLATFORM_BADGE[platform].label}</Text>
              </View>
            ) : null}
            <TextField
              label={t('share.captionLabel')}
              value={caption}
              onChangeText={setCaption}
              placeholder={t('share.captionPlaceholder')}
              autoCapitalize="sentences"
            />
            {error ? <Text style={styles.error}>{error}</Text> : null}
            <Button title={t('share.submit')} onPress={submit} loading={create.isPending} />
            <RecentShares c={c} styles={styles} t={t} />
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const STATUS_KEY: Record<ShareStatus, MessageKey> = {
  pending: 'share.status.pending',
  fetching: 'share.status.fetching',
  analyzing: 'share.status.analyzing',
  review: 'share.status.review',
  published: 'share.status.published',
  failed: 'share.status.failed',
  rejected: 'share.status.rejected',
};

/** The viewer's own recent submissions, with a live-updating status pill. */
function RecentShares({ c, styles, t }: { c: Palette; styles: Styles; t: (key: MessageKey) => string }) {
  const { data: shares } = useShares(10);
  if (!shares || shares.length === 0) return null;

  return (
    <View style={styles.recent}>
      <Text style={styles.recentTitle}>{t('share.recent')}</Text>
      {shares.map((s) => {
        const label =
          s.place?.name ?? s.source_post.caption ?? s.source_post.url?.replace(/^https?:\/\//, '') ?? '—';
        const tone =
          s.status === 'published'
            ? { bg: c.greenSoft, fg: c.green }
            : s.status === 'review'
              ? { bg: c.goldSoft, fg: c.gold }
              : s.status === 'failed' || s.status === 'rejected'
                ? { bg: c.dangerSoft, fg: c.danger }
                : { bg: c.primarySoft, fg: c.primary };
        const go =
          s.place != null
            ? () => router.push({ pathname: '/place/[slug]', params: { slug: s.place!.id } })
            : undefined;
        return (
          <Pressable
            key={s.id}
            accessibilityRole={go ? 'button' : undefined}
            accessibilityLabel={label}
            onPress={go}
            disabled={!go}
            style={({ pressed }) => [styles.recentRow, pressed && go ? styles.rowPressed : null]}
          >
            <Text style={styles.recentLabel} numberOfLines={1}>
              {label}
            </Text>
            <View style={[styles.pill, { backgroundColor: tone.bg }]}>
              <Text style={[styles.pillText, { color: tone.fg }]}>{t(STATUS_KEY[s.status])}</Text>
            </View>
            {go ? <Ionicons name="chevron-forward" size={16} color={c.muted} /> : null}
          </Pressable>
        );
      })}
    </View>
  );
}

function ShareProgress({
  share,
  status,
  onReset,
  replay,
  c,
  styles,
  t,
}: {
  share: ShareDetail | undefined;
  status: ShareStatus | undefined;
  onReset: () => void;
  replay: boolean;
  c: Palette;
  styles: Styles;
  t: (key: MessageKey, params?: Record<string, string | number>) => string;
}) {
  // Add-to-list at share time (T-073): which published place the save sheet targets.
  const [saveFor, setSaveFor] = useState<string | null>(null);
  // Re-run a failed pipeline in place (transient errors: model/ffmpeg/etc.).
  const retry = useRetryShare(share?.id ?? '');

  // A multi-place post (e.g. a "best cafés" reel) publishes several pins; fall
  // back to the single `place` for older payloads.
  const publishedPlaces = share?.places?.length ? share.places : share?.place ? [share.place] : [];
  const pendingCount = share?.pending_place_count ?? 0;

  // A single clean publish (one place, nothing left in review) opens its detail
  // automatically — you land on the place you just added (T-076). Multi-place or
  // partial (pending) publishes keep the result card so no venue is lost. The ref
  // latches it to one fire and lets you return (Back) without being re-pushed.
  // Derived inside the effect (from `share`) so no fresh array lands in the deps.
  const navigatedRef = useRef(false);
  useEffect(() => {
    if (navigatedRef.current || status !== 'published') return;
    const places = share?.places?.length ? share.places : share?.place ? [share.place] : [];
    if (places.length === 1 && (share?.pending_place_count ?? 0) === 0) {
      navigatedRef.current = true;
      router.push({ pathname: '/place/[slug]', params: { slug: places[0].id } });
    }
  }, [status, share]);

  // No status yet, or still moving through the pipeline → spinner + stage label.
  if (!status || !isTerminal(status)) {
    const stageKey = (status && STAGE_KEY[status]) || 'share.stage.pending';
    return (
      <View style={styles.result}>
        <ActivityIndicator color={c.primary} />
        <Text style={styles.resultTitle}>{t('share.processing')}</Text>
        <Text style={styles.resultBody}>{t(stageKey)}</Text>
      </View>
    );
  }

  if (status === 'published' && publishedPlaces.length > 0) {
    return (
      <View style={styles.result}>
        <View style={[styles.badge, styles.badgeOk]}>
          <Ionicons name="checkmark" size={26} color={c.green} />
        </View>
        <Text style={styles.resultTitle}>{t('share.published.title')}</Text>
        {replay ? <Text style={styles.replayNote}>{t('share.duplicate.note')}</Text> : null}
        {publishedPlaces.length === 1 ? (
          <>
            <Text style={styles.placeName}>{publishedPlaces[0].name}</Text>
            <Button
              title={t('place.view')}
              onPress={() => router.push({ pathname: '/place/[slug]', params: { slug: publishedPlaces[0].id } })}
            />
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('share.saveToList')}
              onPress={() => setSaveFor(publishedPlaces[0].id)}
              hitSlop={8}
            >
              <Text style={styles.link}>{t('share.saveToList')}</Text>
            </Pressable>
          </>
        ) : (
          <>
            <Text style={styles.resultBody}>
              {publishedPlaces.length} {t('share.published.countLabel')}
            </Text>
            <View style={styles.placeList}>
              {publishedPlaces.map((p) => (
                <View key={p.id} style={styles.placeRow}>
                  <Pressable
                    accessibilityRole="button"
                    onPress={() => router.push({ pathname: '/place/[slug]', params: { slug: p.id } })}
                    style={styles.placeRowMain}
                  >
                    <Text style={styles.placeRowName} numberOfLines={1}>
                      {p.name}
                    </Text>
                  </Pressable>
                  <Pressable
                    accessibilityRole="button"
                    accessibilityLabel={t('share.saveToListNamed', { name: p.name })}
                    onPress={() => setSaveFor(p.id)}
                    hitSlop={8}
                  >
                    <Ionicons name="bookmark-outline" size={18} color={c.primary} />
                  </Pressable>
                </View>
              ))}
            </View>
          </>
        )}
        {pendingCount > 0 && share ? (
          <PendingVenues shareId={share.id} venues={share.pending_places ?? []} />
        ) : null}
        <Pressable accessibilityRole="button" onPress={onReset} hitSlop={8}>
          <Text style={styles.link}>{t('share.another')}</Text>
        </Pressable>
        {saveFor ? <SaveToListSheet placeId={saveFor} visible onClose={() => setSaveFor(null)} /> : null}
      </View>
    );
  }

  const isReview = status === 'review';
  return (
    <View style={styles.result}>
      <View style={[styles.badge, isReview ? styles.badgeWarn : styles.badgeErr]}>
        <Ionicons name={isReview ? 'alert' : 'close'} size={26} color={isReview ? c.gold : c.danger} />
      </View>
      <Text style={styles.resultTitle}>{isReview ? t('share.review.title') : t('share.failed.title')}</Text>
      {replay ? <Text style={styles.replayNote}>{t('share.duplicate.note')}</Text> : null}
      {share?.failure?.message ? <Text style={styles.resultBody}>{share.failure.message}</Text> : null}
      {status === 'failed' && share ? (
        <Button title={t('share.retry')} onPress={() => retry.mutate()} loading={retry.isPending} />
      ) : null}
      <Pressable accessibilityRole="button" onPress={onReset} hitSlop={8}>
        <Text style={styles.link}>{t('share.another')}</Text>
      </Pressable>
    </View>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    scroll: { padding: 24, gap: 8 },
    title: { fontFamily: fonts.display, fontSize: 30, fontWeight: '800', letterSpacing: -0.5, color: c.text },
    subtitle: { fontSize: 15, color: c.muted, marginBottom: 16, lineHeight: 21 },
    form: { gap: 6 },
    platformBadge: {
      flexDirection: 'row',
      alignItems: 'center',
      alignSelf: 'flex-start',
      gap: 5,
      paddingHorizontal: 10,
      paddingVertical: 4,
      borderRadius: 999,
      backgroundColor: c.primarySoft,
      marginTop: -2,
      marginBottom: 2,
    },
    platformBadgeText: { fontSize: 12, fontWeight: '700', color: c.primary },
    replayNote: { fontSize: 14, color: c.muted, textAlign: 'center', marginTop: -4 },
    error: { color: c.danger, fontSize: 14, marginBottom: 4 },
    result: { alignItems: 'center', gap: 12, paddingVertical: 32 },
    resultTitle: { fontFamily: fonts.display, fontSize: 20, fontWeight: '700', color: c.text },
    resultBody: { fontSize: 15, color: c.muted, textAlign: 'center' },
    placeName: { fontSize: 17, fontWeight: '600', color: c.primary, textAlign: 'center', marginBottom: 4 },
    placeList: { alignSelf: 'stretch', gap: 8, marginTop: 4 },
    placeRow: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'space-between',
      gap: 12,
      paddingVertical: 12,
      paddingHorizontal: 16,
      borderRadius: 12,
      backgroundColor: c.surface,
      borderWidth: 1,
      borderColor: c.border,
    },
    placeRowMain: { flex: 1 },
    placeRowName: { flex: 1, fontSize: 16, fontWeight: '600', color: c.text },
    badge: { width: 56, height: 56, borderRadius: 28, alignItems: 'center', justifyContent: 'center' },
    badgeOk: { backgroundColor: c.greenSoft },
    badgeWarn: { backgroundColor: c.goldSoft },
    badgeErr: { backgroundColor: c.dangerSoft },
    link: { color: c.primary, fontSize: 15, fontWeight: '700', marginTop: 4 },
    recent: { marginTop: 28, gap: 8 },
    recentTitle: { fontSize: 13, fontWeight: '700', letterSpacing: 0.4, textTransform: 'uppercase', color: c.muted },
    recentRow: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      paddingVertical: 12,
      paddingHorizontal: 4,
      borderBottomWidth: StyleSheet.hairlineWidth,
      borderBottomColor: c.border,
    },
    rowPressed: { opacity: 0.6 },
    recentLabel: { flex: 1, fontSize: 15, color: c.text },
    pill: { borderRadius: 999, paddingHorizontal: 10, paddingVertical: 3 },
    pillText: { fontSize: 12, fontWeight: '700' },
  });
