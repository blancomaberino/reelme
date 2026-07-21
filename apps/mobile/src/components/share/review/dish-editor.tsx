import { Ionicons } from '@expo/vector-icons';
import { useMemo, useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';

import type { ExtractionPlace } from '@/api/shares';
import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';

type Dish = ExtractionPlace['dishes'][number];

/**
 * A light editor for the extracted dish list: remove a dish (chip ✕) or add one
 * by name. Existing dish objects keep their `price` / `shown_in_video`; a newly
 * typed dish is added as name-only. The list replaces wholesale on PATCH, so the
 * parent hands the full array back up.
 */
export function DishEditor({
  label,
  dishes,
  onChange,
}: {
  label: string;
  dishes: Dish[];
  onChange: (dishes: Dish[]) => void;
}) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const [draft, setDraft] = useState('');

  const add = () => {
    const name = draft.trim();
    if (!name) return;
    onChange([...dishes, { name, shown_in_video: false, price: null }]);
    setDraft('');
  };

  return (
    <View style={styles.wrap}>
      <Text style={styles.label}>{label}</Text>
      {dishes.length > 0 ? (
        <View style={styles.chips}>
          {dishes.map((d, i) => (
            <View key={`${i}-${d.name}`} style={styles.chip}>
              <Text style={styles.chipText} numberOfLines={1}>
                {d.name}
                {d.price ? <Text style={styles.chipPrice}> · {d.price}</Text> : null}
              </Text>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={`${t('review.discard')} ${d.name}`}
                hitSlop={8}
                onPress={() => onChange(dishes.filter((_, j) => j !== i))}
              >
                <Ionicons name="close" size={15} color={c.muted} />
              </Pressable>
            </View>
          ))}
        </View>
      ) : null}
      <View style={styles.addRow}>
        <TextInput
          accessibilityLabel={t('review.field.addDish')}
          style={styles.input}
          value={draft}
          onChangeText={setDraft}
          placeholder={t('review.field.addDish')}
          placeholderTextColor={c.placeholder}
          selectionColor={c.primary}
          autoCapitalize="sentences"
          returnKeyType="done"
          onSubmitEditing={add}
        />
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('review.field.addDish')}
          onPress={add}
          hitSlop={8}
          style={({ pressed }) => [styles.addBtn, pressed && styles.pressed]}
        >
          <Ionicons name="add" size={22} color={c.primary} />
        </Pressable>
      </View>
    </View>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    wrap: { gap: 8 },
    label: { fontSize: 14, fontWeight: '500', color: c.text },
    chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
    chip: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 6,
      maxWidth: '100%',
      paddingLeft: 12,
      paddingRight: 8,
      paddingVertical: 7,
      borderRadius: 999,
      backgroundColor: c.surface2,
    },
    chipText: { flexShrink: 1, fontSize: 13, fontWeight: '600', color: c.text },
    chipPrice: { color: c.gold, fontWeight: '700' },
    addRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
    input: {
      flex: 1,
      borderWidth: 1,
      borderColor: c.border,
      borderRadius: 12,
      paddingHorizontal: 14,
      paddingVertical: 11,
      fontSize: 15,
      color: c.text,
      backgroundColor: c.surface,
    },
    addBtn: {
      width: 44,
      height: 44,
      borderRadius: 12,
      alignItems: 'center',
      justifyContent: 'center',
      backgroundColor: c.primarySoft,
    },
    pressed: { opacity: 0.6 },
  });
