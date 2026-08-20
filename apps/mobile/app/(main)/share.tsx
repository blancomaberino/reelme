import { Ionicons } from '@expo/vector-icons';
import { isAxiosError } from 'axios';
import { router, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Keyboard, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useCreateShare } from '@/api/hooks/useCreateShare';
import { useQuotas } from '@/api/hooks/useQuotas';
import { usePublishBestGuess } from '@/api/hooks/usePublishBestGuess';
import { useRetryShare } from '@/api/hooks/useRetryShare';
import { useShares } from '@/api/hooks/useShares';
import { useShareStatus } from '@/api/hooks/useShareStatus';
import {
  hasEditableExtraction,
  isTerminal,
  platformFromUrl,
  type ShareDetail,
  type SharePlatform,
  type ShareStatus,
} from '@/api/shares';
import { Button } from '@/components/button';
import { SaveToListSheet } from '@/components/place/save-to-list';
import { PendingVenues } from '@/components/share/pending-venues';
import { ShareRow } from '@/components/share/share-row';
import { TextField } from '@/components/text-field';
import { type MessageKey, useT } from '@/i18n';
import { platformIcon } from '@/lib/format';
import { failureBodyKey } from '@/lib/failure-copy';
import { formatResetAt } from '@/lib/format-reset';
import { isHttpUrl } from '@/lib/linking';
import { type PendingShare, useUiStore } from '@/stores/ui';
import { fonts, type Palette, useColors } from '@/theme/colors';

/** Brand-cased labels for the platform badge; the glyph reuses `platformIcon`. */
const PLATFORM_LABEL: Record<SharePlatform, string> = {
  instagram: 'Instagram',
  tiktok: 'TikTok',
  x: 'X',
  youtube: 'YouTube',
};

/**
 * A route param as Expo Router actually hands it over: a REPEATED key
 * (`?sharedUrl=a&sharedUrl=b`) arrives as an array, not a string — and this
 * screen's params come from a URL any other app can compose.
 */
type ParamValue = string | string[] | undefined;

/** First value of a repeated param; `''` when absent. */
function firstParam(v: ParamValue): string {
  return (Array.isArray(v) ? v[0] : v) ?? '';
}

/**
 * Split an incoming share payload into the URL and caption fields, dropping a
 * value that is not http(s) rather than forwarding it (T-137). Belt to the
 * choke point's braces — see the scheme guard in `doSubmit`.
 */
function splitPayload(rawUrl: string, rawText: string): { url: string; caption: string } {
  const u = rawUrl.trim();
  const txt = rawText.trim();
  const url = isHttpUrl(u) ? u : isHttpUrl(txt) ? txt : '';
  return { url, caption: url ? '' : txt };
}

/**
 * Whether a payload staged by the native share module may submit on its own.
 *
 * A function, not a module constant: a constant is evaluated at import time,
 * which freezes the platform before a test can vary it — and an invariant no
 * test can exercise is a comment.
 *
 * iOS: yes. The share extension writes the payload into the app group, which no
 * other app can write, so its arrival IS the user's share-sheet tap.
 *
 * Android: no. `app.config.ts` registers `androidIntentFilters: ['text/*']` on
 * the exported MainActivity, so any installed app can `startActivity` an
 * EXPLICIT `ACTION_SEND` with `setPackage(us)` — no chooser, no tap — and reach
 * this same store. That is the shape T-137 closed for deep links, so it gets
 * the same answer: prefill and wait for the button. (Traced through
 * expo-share-intent's source; not yet reproduced on an Android device.)
 */
function stagedPayloadIsTrusted(): boolean {
  return Platform.OS === 'ios';
}

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

  // The daily share allowance (T-051). Guarded on `remaining`, not on a 429:
  // the point of surfacing it is that the screen can say so before the tap.
  const { data: quotas } = useQuotas();
  // Trustworthy across midnight UTC because `useQuotas` schedules its own
  // refetch at `resets_at` — see the hook. Nothing here needs to re-check the
  // clock, which is just as well: reading it during render is impure.
  const outOfShares = quotas !== undefined && quotas.shares.remaining === 0;
  // Rendered in the DEVICE's timezone even though the boundary is UTC — "resets
  // at 21:00" is only useful if it is the clock the person is looking at.
  const quotaResetLabel = quotas ? formatResetAt(quotas.resets_at) : '';
  const [error, setError] = useState<string | null>(null);
  const [shareId, setShareId] = useState<string | null>(null);
  // True when the API replayed an existing share (re-shared post) — drives the
  // friendly "you already added this one" note instead of a fresh-pin flow.
  const [replay, setReplay] = useState(false);

  const create = useCreateShare();
  const { data: share } = useShareStatus(shareId);

  const platform = useMemo(() => (url.trim() ? platformFromUrl(url) : null), [url]);

  // Set the moment the person types. A prefill may fill an untouched form; it
  // may not swap out a link they wrote themselves and are about to submit.
  const edited = useRef(false);

  // The one way back to the form. Four places used to clear their own subset of
  // this state and had already drifted apart.
  const clearResult = useCallback(() => {
    setShareId(null);
    setReplay(false);
    setError(null);
  }, []);

  const doSubmit = useCallback(
    (rawUrl: string, rawCaption: string, via: 'paste_url' | 'share_sheet', tapless: boolean) => {
      // A submit nobody tapped is honoured only where the payload cannot be
      // forged (T-137) — `via` stays pure attribution, this is the authorization.
      // Elsewhere the prefill has already happened and the request waits for the
      // button. Two flags rather than one because a future TRUSTED producer will
      // want `share_sheet` attribution without being dropped here.
      //
      // Neither parameter has a default, deliberately: a fail-open `tapless =
      // false` would let the next call site submit without a tap by forgetting
      // an argument. Making it explicit costs one word per call.
      if (tapless && !stagedPayloadIsTrusted()) return;
      const u = rawUrl.trim();
      const cap = rawCaption.trim();
      if (!u && !cap) {
        setError(t('share.needInput'));
        return;
      }
      // THE choke point for the scheme (T-137). `splitPayload` guards the two
      // prefill paths, but this is the only place a request is made — the free
      // text field reaches it too, and so would any call site added later. The
      // API is no backstop: its `url` rule is filter_var, which accepts `ftp://`
      // and `file://` (both verified 202).
      if (u && !isHttpUrl(u)) {
        setError(t('share.needHttpUrl'));
        return;
      }
      // Guarded HERE, not only on the button. The SHARE-SHEET path calls this
      // straight from its effect — the product's PRIMARY entry point — so a
      // disabled button protects the route nobody uses and leaves the important
      // one to meet the limit as a generic "couldn't submit".
      //
      // `outOfShares`/`quotaResetLabel` in the dependency list is safe: a
      // rebuilt callback re-runs the staged effect, which returns early because
      // the store was already cleared (`!staged`).
      if (outOfShares) {
        // No message: the banner below already says exactly this, and the only
        // caller that reaches here is the staged effect (the button is
        // disabled), so setting it would render the same sentence twice.
        clearResult();
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
          // The daily cap, not a transient failure: "couldn't submit, try
          // again" invites a retry that cannot work, and this is the path a
          // share-sheet ingest lands on (it can fire before /me answers, so the
          // server's refusal IS the guard there).
          //
          // Branched on the CODE, not the status — the 10/min burst limiter is
          // also a 429, and telling somebody who tapped twice quickly that they
          // are out for the day is worse than the generic copy.
          onError: (error) =>
            setError(
              isAxiosError(error) && error.response?.data?.error?.code === 'daily_quota_exceeded'
                ? t('share.quotaReached', {
                    // The server's own boundary when it sends one, so the
                    // refusal and the screen that predicted it agree; otherwise
                    // the quota we already hold. `formatResetAt` returns '' on
                    // anything it can't parse, which would render "resets at .".
                    time:
                      formatResetAt(String(error.response?.data?.error?.details?.resets_at ?? '')) ||
                      quotaResetLabel,
                  })
                : t('share.submitError'),
            ),
        },
      );
    },
    [create, t, outOfShares, quotaResetLabel, clearResult],
  );

  const submit = useCallback(() => doSubmit(url, caption, 'paste_url', false), [doSubmit, url, caption]);

  const onEditUrl = useCallback((v: string) => {
    edited.current = true;
    setUrl(v);
  }, []);

  const onEditCaption = useCallback((v: string) => {
    edited.current = true;
    setCaption(v);
  }, []);

  const reset = useCallback(() => {
    clearResult();
    setUrl('');
    setCaption('');
    edited.current = false;
  }, [clearResult]);

  // An incoming payload arrives by one of two routes, and they are NOT the same
  // path (T-137):
  //
  //   1. `useUiStore.pendingShare`, staged by the root ShareIntentRedirect from
  //      the native share module. Auto-submits, but only where that payload
  //      cannot be forged — see `stagedPayloadIsTrusted`. Survives the sign-in
  //      redirect, which is why it is staged rather than passed.
  //   2. `sharedUrl`/`sharedText` route params, which reach us from ANY
  //      `reelmap://share?sharedUrl=…` deep link — another installed app, or a
  //      web page, can open one. Reproduced 2026-08-20: it published a share and
  //      spent a daily allowance with nobody touching the phone. So a param
  //      payload PREFILLS the form and waits for the button, which is what the
  //      Maestro/CI flows this path exists for were always doing anyway.
  //
  // Params are read through `firstParam` because a repeated key arrives as an
  // array (see `ParamValue`), and `.trim()` on an array takes the screen down.
  const { sharedUrl, sharedText } = useLocalSearchParams<{ sharedUrl?: ParamValue; sharedText?: ParamValue }>();
  const staged = useUiStore((s) => s.pendingShare);

  // Keyed on the staged OBJECT, not on its text: staging the same post again is
  // a new object, so "share another" then re-sharing the same reel submits (and
  // reaches the idempotent-replay note) instead of silently doing nothing.
  //
  // A ref is genuinely needed here, unlike on the param effect below: `doSubmit`
  // is rebuilt on most renders, so this effect re-runs constantly.
  const handledStaged = useRef<PendingShare | null>(null);
  useEffect(() => {
    if (!staged || handledStaged.current === staged) return;
    handledStaged.current = staged;
    // Consumed exactly once; clearing re-runs this effect with `staged` null.
    useUiStore.getState().setPendingShare(null);
    // On a platform where this payload can be forged, it gets the same courtesy
    // as a deep link: it may fill an untouched form, but it may not swap out a
    // link the person typed and is about to submit. On iOS it always wins —
    // there the payload IS a share-sheet tap the person just performed.
    if (!stagedPayloadIsTrusted() && edited.current) return;
    const { url: u, caption: cap } = splitPayload(staged.url, staged.text);
    // A previous share's result may still be on screen — this screen never
    // unmounts — and it renders INSTEAD of the form, which would make the
    // prefill, or a refusal, invisible.
    clearResult();
    setUrl(u);
    setCaption(cap);
    edited.current = false;
    doSubmit(u, cap, 'share_sheet', true);
  }, [staged, doSubmit, clearResult]);

  // Prefill only — no `doSubmit` anywhere in this effect, which is the whole
  // point of T-137. The deps are the two flattened strings the body reads, so
  // this runs only when the params actually change; no dedupe ref is needed
  // (and a repeated key would otherwise churn a fresh array identity every
  // render).
  //
  // KNOWN LIMITATION, not papered over: re-opening the SAME link after "share
  // another" prefills nothing, because the params did not change and React
  // cannot re-run the effect. Fixing it means consuming the params
  // (`router.setParams`) — a routing change this task does not need, since only
  // external links and the CI flows use this path. The share-sheet path, which
  // people actually use, handles a repeat correctly.
  const rawParamUrl = firstParam(sharedUrl);
  const rawParamText = firstParam(sharedText);
  useEffect(() => {
    const { url: u, caption: cap } = splitPayload(rawParamUrl, rawParamText);
    // Nothing usable — no params, or the scheme was dropped. Return WITHOUT
    // touching the fields: an externally-composed URL must not be able to wipe
    // what the person has typed any more than it can submit it.
    if (!u && !cap) return;
    if (edited.current) return;
    clearResult();
    setUrl(u);
    setCaption(cap);
  }, [rawParamUrl, rawParamText, clearResult]);

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
              // The label Text and the input BOTH carry "Enlace" (one as text,
              // one as accessibilityLabel), and Maestro matched the label — so
              // the E2E flows were typing into nothing and asserting on a form
              // they had never filled in. A testID is the unambiguous handle.
              testID="share-url"
              label={t('share.urlLabel')}
              value={url}
              onChangeText={onEditUrl}
              placeholder={t('share.urlPlaceholder')}
              keyboardType="url"
              autoCorrect={false}
              autoCapitalize="none"
              returnKeyType="next"
            />
            {platform ? (
              <View
                accessible
                accessibilityRole="text"
                accessibilityLabel={t('share.platformDetected', { platform: PLATFORM_LABEL[platform] })}
                style={styles.platformBadge}
              >
                <Ionicons name={platformIcon(platform)} size={14} color={c.primary} />
                <Text style={styles.platformBadgeText}>{PLATFORM_LABEL[platform]}</Text>
              </View>
            ) : null}
            <TextField
              label={t('share.captionLabel')}
              value={caption}
              onChangeText={onEditCaption}
              placeholder={t('share.captionPlaceholder')}
              autoCapitalize="sentences"
            />
            {error ? <Text style={styles.error}>{error}</Text> : null}
            {/* Said BEFORE the tap, not after a 429. The limit is a designed
                behaviour, and discovering it by being refused reads as a bug —
                so the screen states it, with the reset time, and stops the
                request rather than spending it on a rejection. */}
            {outOfShares ? (
              <Text style={styles.quota} testID="share-quota-reached">
                {t('share.quotaReached', { time: quotaResetLabel })}
              </Text>
            ) : null}
            <Button
              title={t('share.submit')}
              onPress={submit}
              loading={create.isPending}
              disabled={outOfShares}
              testID="share-submit"
            />
            <RecentShares styles={styles} t={t} />
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

/** The viewer's own recent submissions, with a live-updating status pill. */
function RecentShares({ styles, t }: { styles: Styles; t: (key: MessageKey) => string }) {
  const { data: shares } = useShares(10);
  if (!shares || shares.length === 0) return null;

  return (
    <View style={styles.recent}>
      <Text style={styles.recentTitle}>{t('share.recent')}</Text>
      {shares.map((s) => (
        <ShareRow key={s.id} share={s} />
      ))}
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
  const skip = usePublishBestGuess(share?.id ?? '');

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
  // A review with an extraction the user can correct → hand off to the dedicated
  // review-and-publish form (T-026). A review WITHOUT one (a fetch failure) has
  // nothing to edit, so it stays here with the failure copy + retry.
  const canReview = isReview && share != null && hasEditableExtraction(share);
  // Confirm-before-publish (T-098): an uncertain but placeable review can be
  // published as-is — the sharer doesn't have to revise. Best-guess goes live +
  // gets flagged for an admin. Not a chore; a quick optional check.
  const canSkip = isReview && !!share?.can_publish_best_guess;
  return (
    <View style={styles.result}>
      <View style={[styles.badge, isReview ? styles.badgeWarn : styles.badgeErr]}>
        <Ionicons name={isReview ? 'alert' : 'close'} size={26} color={isReview ? c.gold : c.danger} />
      </View>
      <Text style={styles.resultTitle}>
        {isReview ? (canSkip ? t('share.confirm.title') : t('share.review.title')) : t('share.failed.title')}
      </Text>
      {replay ? <Text style={styles.replayNote}>{t('share.duplicate.note')}</Text> : null}
      {canSkip ? (
        <Text style={styles.resultBody}>{t('share.confirm.body')}</Text>
      ) : share?.failure ? (
        // The localized copy for the CODE, not the server's `failure.message`.
        // That string is written in English by the API, which has no idea what
        // locale the device is in — so it rendered an English sentence in the
        // middle of a Spanish screen. Same mapping the status screen uses.
        <Text style={styles.resultBody}>{t(failureBodyKey(share.failure.code))}</Text>
      ) : null}
      {canReview ? (
        <Button
          title={t('shares.action.review')}
          onPress={() => router.push({ pathname: '/shares/[id]/review', params: { id: share.id } })}
        />
      ) : null}
      {canSkip && share ? (
        <Button
          title={t('shares.action.publishAsIs')}
          variant="secondary"
          onPress={() =>
            skip.mutate(undefined, {
              onSuccess: () => router.push({ pathname: '/shares/[id]/status', params: { id: share.id } }),
            })
          }
          loading={skip.isPending}
        />
      ) : null}
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
    quota: { color: c.danger, fontSize: 13, lineHeight: 18 },
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
  });
