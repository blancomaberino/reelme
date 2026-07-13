import { Ionicons } from '@expo/vector-icons';
import { router, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Keyboard, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useCreateShare } from '@/api/hooks/useCreateShare';
import { useShareStatus } from '@/api/hooks/useShareStatus';
import { isTerminal, type ShareDetail, type ShareStatus } from '@/api/shares';
import { Button } from '@/components/button';
import { TextField } from '@/components/text-field';
import { type MessageKey, useT } from '@/i18n';
import { fonts, type Palette, useColors } from '@/theme/colors';

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

  const create = useCreateShare();
  const { data: share } = useShareStatus(shareId);

  const doSubmit = useCallback(
    (rawUrl: string, rawCaption: string) => {
      const u = rawUrl.trim();
      const cap = rawCaption.trim();
      if (!u && !cap) {
        setError(t('share.needInput'));
        return;
      }
      setError(null);
      Keyboard.dismiss();
      create.mutate(
        { url: u, caption: cap },
        {
          onSuccess: (s) => setShareId(s.id),
          onError: () => setError(t('share.submitError')),
        },
      );
    },
    [create, t],
  );

  const submit = useCallback(() => doSubmit(url, caption), [doSubmit, url, caption]);

  const reset = useCallback(() => {
    setShareId(null);
    setUrl('');
    setCaption('');
    setError(null);
  }, []);

  // A link/text shared in from another app (Instagram, Safari…) via the iOS
  // share sheet: prefill and auto-submit once. A non-URL payload goes to the
  // caption; `handled` guards against re-firing on re-render / re-focus.
  const { sharedUrl, sharedText } = useLocalSearchParams<{ sharedUrl?: string; sharedText?: string }>();
  const handled = useRef('');
  useEffect(() => {
    const u = (sharedUrl ?? '').trim();
    const txt = (sharedText ?? '').trim();
    const payload = u || txt;
    if (!payload || handled.current === payload) return;
    handled.current = payload;
    const looksUrl = /^https?:\/\//i.test(txt);
    const finalUrl = u || (looksUrl ? txt : '');
    const finalCap = finalUrl ? '' : txt;
    setUrl(finalUrl);
    setCaption(finalCap);
    doSubmit(finalUrl, finalCap);
  }, [sharedUrl, sharedText, doSubmit]);

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
        <Text style={styles.title}>{t('share.title')}</Text>
        <Text style={styles.subtitle}>{t('share.subtitle')}</Text>

        {shareId ? (
          <ShareProgress share={share} status={share?.status} onReset={reset} c={c} styles={styles} t={t} />
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
            <TextField
              label={t('share.captionLabel')}
              value={caption}
              onChangeText={setCaption}
              placeholder={t('share.captionPlaceholder')}
              autoCapitalize="sentences"
            />
            {error ? <Text style={styles.error}>{error}</Text> : null}
            <Button title={t('share.submit')} onPress={submit} loading={create.isPending} />
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

function ShareProgress({
  share,
  status,
  onReset,
  c,
  styles,
  t,
}: {
  share: ShareDetail | undefined;
  status: ShareStatus | undefined;
  onReset: () => void;
  c: Palette;
  styles: Styles;
  t: (key: MessageKey) => string;
}) {
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

  if (status === 'published' && share?.place) {
    return (
      <View style={styles.result}>
        <View style={[styles.badge, styles.badgeOk]}>
          <Ionicons name="checkmark" size={26} color={c.green} />
        </View>
        <Text style={styles.resultTitle}>{t('share.published.title')}</Text>
        <Text style={styles.placeName}>{share.place.name}</Text>
        <Button
          title={t('place.view')}
          onPress={() => router.push({ pathname: '/place/[slug]', params: { slug: share.place!.id } })}
        />
        <Pressable accessibilityRole="button" onPress={onReset} hitSlop={8}>
          <Text style={styles.link}>{t('share.another')}</Text>
        </Pressable>
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
      {share?.failure?.message ? <Text style={styles.resultBody}>{share.failure.message}</Text> : null}
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
    error: { color: c.danger, fontSize: 14, marginBottom: 4 },
    result: { alignItems: 'center', gap: 12, paddingVertical: 32 },
    resultTitle: { fontFamily: fonts.display, fontSize: 20, fontWeight: '700', color: c.text },
    resultBody: { fontSize: 15, color: c.muted, textAlign: 'center' },
    placeName: { fontSize: 17, fontWeight: '600', color: c.primary, textAlign: 'center', marginBottom: 4 },
    badge: { width: 56, height: 56, borderRadius: 28, alignItems: 'center', justifyContent: 'center' },
    badgeOk: { backgroundColor: c.greenSoft },
    badgeWarn: { backgroundColor: c.goldSoft },
    badgeErr: { backgroundColor: c.dangerSoft },
    link: { color: c.primary, fontSize: 15, fontWeight: '700', marginTop: 4 },
  });
