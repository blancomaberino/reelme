import { Ionicons } from '@expo/vector-icons';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Keyboard, Modal, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useCreateShare } from '@/api/hooks/useCreateShare';
import { useShareStatus } from '@/api/hooks/useShareStatus';
import { isTerminal, type SharePlace } from '@/api/shares';
import { useT } from '@/i18n';
import { fonts, type Palette, useColors } from '@/theme/colors';

type Props = {
  visible: boolean;
  onClose: () => void;
  /** Called with the published place so the map can fly to its pin. */
  onPublished: (place: SharePlace) => void;
};

/**
 * Quick "add from a link" popup on the map (T-025 lite). Paste a link/caption →
 * submit → poll the pipeline; on publish, hand the place back so the map flies
 * to the new pin and closes — without leaving the map.
 */
export function QuickShareModal({ visible, onClose, onPublished }: Props) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const [text, setText] = useState('');
  const create = useCreateShare();
  // The share this modal created, if any — derived straight from the mutation,
  // so there's no state to sync (and the parent mounts a fresh modal each open,
  // so no stale id ever carries over). Polling stops itself on a terminal state.
  const shareId = create.data?.id ?? null;
  const { data: share } = useShareStatus(shareId);
  const status = share?.status;
  const done = status ? isTerminal(status) : false;

  // On publish → hand the place to the parent (map flies to the pin) and close.
  // A ref latch keeps it to exactly one fire even if the effect re-runs
  // (StrictMode / a concurrent re-render) before the close unmounts us.
  const publishedRef = useRef(false);
  useEffect(() => {
    if (status === 'published' && share?.place && !publishedRef.current) {
      publishedRef.current = true;
      onPublished(share.place);
      onClose();
    }
  }, [status, share?.place, onPublished, onClose]);

  const submit = useCallback(() => {
    const v = text.trim();
    if (!v) return;
    Keyboard.dismiss();
    const looksUrl = /^https?:\/\//i.test(v);
    create.mutate(looksUrl ? { url: v } : { caption: v });
  }, [text, create]);

  // "Share another" from a review/failed state → drop the mutation, back to input.
  const retry = useCallback(() => create.reset(), [create]);

  // Keep the spinner up through the publish→close transition (published is
  // terminal, but we're about to fly the map and dismiss).
  const processing = create.isPending || (shareId !== null && (!done || status === 'published'));

  return (
    <Modal visible={visible} animationType="slide" transparent onRequestClose={onClose}>
      <Pressable style={styles.backdrop} onPress={onClose} />
      <SafeAreaView style={styles.sheet} edges={['bottom']}>
        <View style={styles.handle} />
        <View style={styles.header}>
          <Text style={styles.title}>{t('quickShare.title')}</Text>
          <Pressable accessibilityRole="button" accessibilityLabel={t('save.done')} onPress={onClose} hitSlop={8}>
            <Ionicons name="close" size={24} color={c.text} />
          </Pressable>
        </View>

        {processing ? (
          <View style={styles.processing}>
            <ActivityIndicator color={c.primary} />
            <Text style={styles.processingText}>{t('share.processing')}</Text>
          </View>
        ) : done && status !== 'published' ? (
          <View style={styles.processing}>
            <Ionicons name="alert-circle-outline" size={28} color={c.gold} />
            <Text style={styles.processingText}>
              {share?.failure?.message ?? t('share.review.title')}
            </Text>
            <Pressable accessibilityRole="button" onPress={retry} hitSlop={8}>
              <Text style={styles.retry}>{t('share.another')}</Text>
            </Pressable>
          </View>
        ) : (
          <>
            <TextInput
              style={styles.input}
              value={text}
              onChangeText={setText}
              placeholder={t('quickShare.placeholder')}
              placeholderTextColor={c.placeholder}
              autoCapitalize="none"
              autoCorrect={false}
              autoFocus
              returnKeyType="go"
              onSubmitEditing={submit}
            />
            {create.isError ? <Text style={styles.error}>{t('share.submitError')}</Text> : null}
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('quickShare.submit')}
              onPress={submit}
              style={({ pressed }) => [styles.submit, pressed && styles.submitPressed]}
            >
              <Text style={styles.submitText}>{t('quickShare.submit')}</Text>
            </Pressable>
          </>
        )}
      </SafeAreaView>
    </Modal>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    backdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.35)' },
    sheet: {
      backgroundColor: c.background,
      borderTopLeftRadius: 22,
      borderTopRightRadius: 22,
      paddingHorizontal: 20,
      paddingTop: 8,
      paddingBottom: 16,
      gap: 12,
    },
    handle: { alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: c.border, marginBottom: 6 },
    header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
    title: { fontFamily: fonts.display, fontSize: 20, fontWeight: '700', color: c.text },
    input: {
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      borderRadius: 12,
      paddingHorizontal: 14,
      paddingVertical: 13,
      fontSize: 15,
      color: c.text,
      backgroundColor: c.surface,
    },
    error: { color: c.danger, fontSize: 13 },
    submit: { backgroundColor: c.primary, borderRadius: 14, paddingVertical: 15, alignItems: 'center' },
    submitPressed: { backgroundColor: c.primaryPressed },
    submitText: { color: c.onPrimary, fontSize: 16, fontWeight: '700' },
    processing: { alignItems: 'center', gap: 10, paddingVertical: 24 },
    processingText: { fontSize: 15, color: c.muted, textAlign: 'center' },
    retry: { color: c.primary, fontSize: 15, fontWeight: '700', marginTop: 4 },
  });
