import { Ionicons } from '@expo/vector-icons';
import { useMemo, useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import { usePlaceTags } from '@/api/hooks/usePlaceTags';
import type { MyPlaceTag } from '@/api/places';
import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';

type Props = {
  /** Slug (or id) used to scope the tag routes; matches usePlace's key. */
  slug: string;
  /** The viewer's current private tags for this place (from place.my_tags). */
  tags: MyPlaceTag[];
};

/**
 * Owner-only "My tags" section (T-064): the viewer's private, personal labels
 * for a place (e.g. "visitar a las 5"). Only rendered for the authed owner by
 * the caller. Add via the inline field; tap a chip's ✕ to remove. Writes go
 * through usePlaceTags, which invalidates the place detail so the list here
 * reflects the server's owner-scoped truth.
 */
export function MyTags({ slug, tags }: Props) {
  const t = useT();
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  const [text, setText] = useState('');
  const [error, setError] = useState<string | null>(null);
  const { add, remove } = usePlaceTags(slug);

  const submit = () => {
    const v = text.trim();
    if (v === '' || add.isPending) return;
    setError(null);
    // Clear the field only once the add succeeds — a failed write keeps the
    // typed text so the user can retry, and surfaces an inline error (mirrors
    // ReviewComposer). Removes are low-stakes and stay fire-and-forget.
    add.mutate(v, {
      onSuccess: () => setText(''),
      onError: () => setError(t('myTags.error')),
    });
  };

  return (
    <View style={styles.block}>
      <Text style={styles.title}>{t('myTags.title')}</Text>
      <Text style={styles.hint}>{t('myTags.hint')}</Text>

      {tags.length > 0 ? (
        <View style={styles.chips}>
          {tags.map((tag) => (
            <View key={tag.id} style={styles.chip}>
              <Text style={styles.chipLabel} numberOfLines={1}>
                {tag.label}
              </Text>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t('myTags.remove', { label: tag.label })}
                onPress={() => remove.mutate(tag.id)}
                hitSlop={8}
                style={styles.remove}
              >
                <Ionicons name="close" size={14} color={c.secondary} />
              </Pressable>
            </View>
          ))}
        </View>
      ) : null}

      <View style={styles.inputRow}>
        <TextInput
          value={text}
          onChangeText={setText}
          onSubmitEditing={submit}
          placeholder={t('myTags.placeholder')}
          placeholderTextColor={c.muted}
          returnKeyType="done"
          maxLength={60}
          style={styles.input}
        />
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('myTags.add')}
          onPress={submit}
          disabled={text.trim() === '' || add.isPending}
          style={({ pressed }) => [
            styles.addButton,
            (text.trim() === '' || add.isPending) && styles.addButtonDisabled,
            pressed && styles.pressed,
          ]}
        >
          <Ionicons name="add" size={20} color={c.onPrimary} />
        </Pressable>
      </View>

      {error ? <Text style={styles.error}>{error}</Text> : null}
    </View>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    block: { gap: 10 },
    title: { fontSize: 17, fontWeight: '700', color: c.text },
    hint: { fontSize: 13, color: c.muted, marginTop: -4 },
    chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
    chip: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 6,
      paddingLeft: 12,
      paddingRight: 6,
      paddingVertical: 6,
      borderRadius: 999,
      backgroundColor: c.secondarySoft,
    },
    chipLabel: { color: c.secondary, fontSize: 13, fontWeight: '600', maxWidth: 200 },
    remove: { padding: 2 },
    inputRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
    input: {
      flex: 1,
      height: 44,
      paddingHorizontal: 14,
      borderRadius: 12,
      backgroundColor: c.surface,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      color: c.text,
      fontSize: 15,
    },
    addButton: {
      width: 44,
      height: 44,
      borderRadius: 12,
      alignItems: 'center',
      justifyContent: 'center',
      backgroundColor: c.primary,
    },
    addButtonDisabled: { opacity: 0.4 },
    pressed: { opacity: 0.6 },
    error: { fontSize: 13, color: c.danger },
  });
