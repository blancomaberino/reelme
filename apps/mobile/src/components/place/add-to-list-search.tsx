import { Ionicons } from '@expo/vector-icons';
import { useMemo, useState } from 'react';
import { ActivityIndicator, Modal, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useListMembership } from '@/api/hooks/useLists';
import { useSearch } from '@/api/hooks/useSearch';
import type { PlaceSummary } from '@/api/places';
import { useT } from '@/i18n';
import { useFormat } from '@/lib/use-format';
import { useDebounced } from '@/lib/use-debounced';
import { fonts, type Palette, useColors } from '@/theme/colors';

type Props = {
  visible: boolean;
  onClose: () => void;
  listId: string;
  /** Ids already in the list — shown as "saved" and non-addable. */
  memberIds: Set<string>;
};

/**
 * Search for a place and add it to the current list, from within the list
 * screen. Places already in the list show a check; tapping a new one adds it
 * (the list detail + index invalidate, so the row appears on close).
 */
export function AddPlaceToListSheet({ visible, onClose, listId, memberIds }: Props) {
  const c = useColors();
  const t = useT();
  const fmt = useFormat();
  const styles = useMemo(() => makeStyles(c), [c]);

  const [q, setQ] = useState('');
  const debouncedQ = useDebounced(q, 300);
  const typed = q.trim();
  const searched = debouncedQ.trim();
  const caughtUp = searched === typed;
  const { data, isFetching, isError } = useSearch(debouncedQ);
  const { add } = useListMembership();

  // Optimistic set of ids added during this session (so a tapped place flips to
  // "saved" immediately, on top of the ids that were already members).
  const [added, setAdded] = useState<Set<string>>(new Set());
  const isMember = (id: string) => memberIds.has(id) || added.has(id);

  const places = data?.places ?? [];
  const showEmpty = typed.length >= 2 && caughtUp && !isFetching && places.length === 0 && !isError;

  const onAdd = (place: PlaceSummary) => {
    if (isMember(place.id)) return;
    setAdded((prev) => new Set(prev).add(place.id));
    add.mutate(
      { listId, placeId: place.id },
      {
        onError: () =>
          setAdded((prev) => {
            const next = new Set(prev);
            next.delete(place.id);
            return next;
          }),
      },
    );
  };

  const close = () => {
    setQ('');
    setAdded(new Set());
    onClose();
  };

  return (
    <Modal visible={visible} animationType="slide" transparent onRequestClose={close}>
      <Pressable style={styles.backdrop} onPress={close} />
      <SafeAreaView style={styles.sheet} edges={['bottom']}>
        <View style={styles.handle} />
        <View style={styles.header}>
          <Text style={styles.title}>{t('save.addPlace')}</Text>
          <Pressable accessibilityRole="button" accessibilityLabel={t('save.done')} onPress={close} hitSlop={8}>
            <Ionicons name="close" size={24} color={c.text} />
          </Pressable>
        </View>

        <View style={styles.inputWrap}>
          <Ionicons name="search" size={18} color={c.muted} />
          <TextInput
            style={styles.input}
            placeholder={t('search.placeholder')}
            placeholderTextColor={c.placeholder}
            value={q}
            onChangeText={setQ}
            autoFocus
            autoCorrect={false}
            autoCapitalize="none"
            returnKeyType="search"
            accessibilityLabel={t('feed.search')}
          />
          {q.length > 0 ? (
            <Pressable accessibilityLabel={t('search.clear')} onPress={() => setQ('')} hitSlop={8}>
              <Ionicons name="close-circle" size={18} color={c.placeholder} />
            </Pressable>
          ) : null}
        </View>

        {typed.length < 2 ? (
          <Text style={styles.hint}>{t('search.hint')}</Text>
        ) : isError ? (
          <Text style={styles.hint}>{t('search.error')}</Text>
        ) : showEmpty ? (
          <Text style={styles.hint}>{t('search.noResults', { query: typed })}</Text>
        ) : (
          <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={styles.scroll}>
            {places.map((p) => {
              const member = isMember(p.id);
              return (
                <Pressable
                  key={p.id}
                  accessibilityRole="button"
                  accessibilityLabel={p.name}
                  accessibilityState={{ selected: member }}
                  onPress={() => onAdd(p)}
                  disabled={member}
                  style={({ pressed }) => [styles.row, pressed && !member && styles.pressed]}
                >
                  <Ionicons
                    name={member ? 'checkmark-circle' : 'add-circle-outline'}
                    size={24}
                    color={member ? c.primary : c.muted}
                  />
                  <View style={styles.rowBody}>
                    <Text style={styles.rowName} numberOfLines={1}>
                      {p.name}
                    </Text>
                    <Text style={styles.rowSub} numberOfLines={1}>
                      {[fmt.priceLine(p.category, p.price_range), p.city].filter(Boolean).join(' · ')}
                    </Text>
                  </View>
                  {member ? <Text style={styles.saved}>{t('save.saved')}</Text> : null}
                </Pressable>
              );
            })}
            {isFetching ? <ActivityIndicator color={c.primary} style={styles.loading} /> : null}
          </ScrollView>
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
      maxHeight: '85%',
    },
    handle: { alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: c.border, marginBottom: 10 },
    header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 },
    title: { fontFamily: fonts.display, fontSize: 21, fontWeight: '700', color: c.text },
    inputWrap: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 8,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      borderRadius: 12,
      paddingHorizontal: 12,
      backgroundColor: c.surface,
    },
    input: { flex: 1, paddingVertical: 12, fontSize: 15, color: c.text },
    hint: { fontSize: 14, color: c.muted, textAlign: 'center', paddingVertical: 28, paddingHorizontal: 20 },
    scroll: { paddingVertical: 8 },
    row: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 12,
      paddingVertical: 12,
      borderBottomWidth: StyleSheet.hairlineWidth,
      borderBottomColor: c.border,
    },
    pressed: { opacity: 0.6 },
    rowBody: { flex: 1, gap: 2 },
    rowName: { fontFamily: fonts.display, fontSize: 16, fontWeight: '700', color: c.text },
    rowSub: { fontSize: 13, color: c.muted },
    saved: { fontSize: 13, fontWeight: '700', color: c.primary },
    loading: { paddingVertical: 16 },
  });
