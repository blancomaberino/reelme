import { Ionicons } from '@expo/vector-icons';
import { useMemo, useState } from 'react';
import { Alert, Keyboard, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import { useReview } from '@/api/hooks/useReview';
import type { AppReview } from '@/api/places';
import { Button } from '@/components/button';
import { useT } from '@/i18n';
import { useSessionStore } from '@/stores/session';
import { fonts, type Palette, useColors } from '@/theme/colors';

type Props = {
  placeId: string;
  slug: string;
  /** The viewer's existing review, if any (prefills the form). */
  own: AppReview | null;
};

/**
 * Rate + review a place from the mobile app (T-059). A 1–5 star selector, an
 * optional note, and Post/Update + Delete. Only shown to authed viewers; guests
 * see a sign-in prompt. Prefilled from the viewer's existing review.
 */
export function ReviewComposer({ placeId, slug, own }: Props) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const authed = useSessionStore((s) => s.status === 'authed');
  const { save, remove } = useReview(slug);

  const [rating, setRating] = useState(own?.rating ?? 0);
  const [body, setBody] = useState(own?.body ?? '');
  const [error, setError] = useState<string | null>(null);

  if (!authed) {
    return (
      <View style={styles.signIn}>
        <Text style={styles.signInText}>{t('review.signInPrompt')}</Text>
      </View>
    );
  }

  const onSave = () => {
    if (rating < 1) return;
    setError(null);
    Keyboard.dismiss();
    save.mutate(
      { placeId, rating, body: body.trim() || null },
      { onError: () => setError(t('review.error')) },
    );
  };

  const onDelete = () => {
    Alert.alert(t('review.deleteConfirm.title'), t('review.deleteConfirm.message'), [
      { text: t('common.cancel'), style: 'cancel' },
      {
        text: t('review.delete'),
        style: 'destructive',
        onPress: () =>
          remove.mutate(placeId, {
            onSuccess: () => {
              setRating(0);
              setBody('');
            },
          }),
      },
    ]);
  };

  return (
    <View style={styles.box}>
      <Text style={styles.title}>{own ? t('review.your') : t('review.rate')}</Text>
      <View style={styles.stars}>
        {[1, 2, 3, 4, 5].map((n) => (
          <Pressable
            key={n}
            accessibilityRole="button"
            accessibilityLabel={`${n}`}
            onPress={() => setRating(n)}
            hitSlop={6}
          >
            <Ionicons name={n <= rating ? 'star' : 'star-outline'} size={30} color={c.gold} />
          </Pressable>
        ))}
      </View>
      <TextInput
        style={styles.input}
        value={body}
        onChangeText={setBody}
        placeholder={t('review.placeholder')}
        placeholderTextColor={c.placeholder}
        multiline
        maxLength={2000}
      />
      {error ? <Text style={styles.error}>{error}</Text> : null}
      <View style={styles.actions}>
        <View style={styles.grow}>
          <Button
            title={own ? t('review.update') : t('review.post')}
            onPress={onSave}
            loading={save.isPending}
            disabled={rating < 1}
          />
        </View>
        {own ? (
          <Button title={t('review.delete')} variant="secondary" onPress={onDelete} loading={remove.isPending} />
        ) : null}
      </View>
    </View>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    box: {
      backgroundColor: c.surface,
      borderRadius: 16,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      padding: 14,
      gap: 10,
    },
    title: { fontFamily: fonts.display, fontSize: 16, fontWeight: '700', color: c.text },
    stars: { flexDirection: 'row', gap: 6 },
    input: {
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      borderRadius: 12,
      paddingHorizontal: 12,
      paddingVertical: 10,
      fontSize: 15,
      color: c.text,
      minHeight: 44,
      backgroundColor: c.background,
    },
    error: { color: c.danger, fontSize: 13 },
    actions: { flexDirection: 'row', gap: 10, alignItems: 'center' },
    grow: { flex: 1 },
    signIn: {
      backgroundColor: c.surface,
      borderRadius: 16,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      padding: 16,
      alignItems: 'center',
    },
    signInText: { fontSize: 14, color: c.muted },
  });
