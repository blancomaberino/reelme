import { Ionicons } from '@expo/vector-icons';
import { Stack, router } from 'expo-router';
import { useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useCreateList, useLists } from '@/api/hooks/useLists';
import { useT } from '@/i18n';
import { fonts, type Palette, useColors } from '@/theme/colors';

/** The viewer's place lists (T-062), reachable from Profile. */
export default function ListsScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { data: lists, isLoading } = useLists();
  const create = useCreateList();
  const [name, setName] = useState('');
  const [creating, setCreating] = useState(false);

  const submit = () => {
    const n = name.trim();
    if (!n) return;
    create.mutate(n, {
      onSuccess: (list) => {
        setName('');
        setCreating(false);
        router.push({ pathname: '/lists/[id]', params: { id: list.id, name: list.name } });
      },
    });
  };

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.header}>
        <Pressable accessibilityRole="button" accessibilityLabel={t('place.back')} onPress={() => router.back()} hitSlop={12}>
          <Ionicons name="chevron-back" size={26} color={c.text} />
        </Pressable>
        <Text style={styles.title}>{t('lists.title')}</Text>
        <Pressable accessibilityRole="button" accessibilityLabel={t('lists.new')} onPress={() => setCreating((v) => !v)} hitSlop={12}>
          <Ionicons name="add" size={26} color={c.primary} />
        </Pressable>
      </View>

      {creating ? (
        <View style={styles.createRow}>
          <TextInput
            style={styles.input}
            value={name}
            onChangeText={setName}
            placeholder={t('lists.namePlaceholder')}
            placeholderTextColor={c.placeholder}
            autoFocus
            returnKeyType="done"
            onSubmitEditing={submit}
          />
          <Pressable accessibilityRole="button" accessibilityLabel={t('lists.create')} onPress={submit} style={styles.createBtn}>
            <Text style={styles.createBtnText}>{t('lists.create')}</Text>
          </Pressable>
        </View>
      ) : null}

      {isLoading ? (
        <ActivityIndicator color={c.primary} style={styles.loading} />
      ) : (lists ?? []).length === 0 ? (
        <View style={styles.empty}>
          <Ionicons name="bookmark-outline" size={40} color={c.muted} />
          <Text style={styles.emptyText}>{t('lists.empty')}</Text>
        </View>
      ) : (
        <ScrollView contentContainerStyle={styles.list}>
          {(lists ?? []).map((l) => (
            <Pressable
              key={l.id}
              accessibilityRole="button"
              accessibilityLabel={l.name}
              onPress={() => router.push({ pathname: '/lists/[id]', params: { id: l.id, name: l.name } })}
              style={({ pressed }) => [styles.row, pressed && styles.pressed]}
            >
              <View style={styles.rowIcon}>
                <Ionicons name="albums-outline" size={22} color={c.primary} />
              </View>
              <View style={styles.rowBody}>
                <Text style={styles.rowName} numberOfLines={1}>
                  {l.name}
                </Text>
                <Text style={styles.rowSub}>
                  {t('lists.itemsCount', { count: l.items_count })}
                  {l.is_public ? ` · ${t('lists.public')}` : ''}
                </Text>
              </View>
              <Ionicons name="chevron-forward" size={18} color={c.muted} />
            </Pressable>
          ))}
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 16, paddingVertical: 12, gap: 12 },
    title: { flex: 1, fontSize: 22, fontWeight: '700', color: c.text },
    loading: { paddingVertical: 40 },
    empty: { alignItems: 'center', gap: 10, paddingTop: 80, paddingHorizontal: 40 },
    emptyText: { fontSize: 15, color: c.muted, textAlign: 'center' },
    list: { paddingHorizontal: 16, paddingBottom: 24, gap: 4 },
    row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 14, paddingHorizontal: 4, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: c.border },
    pressed: { opacity: 0.6 },
    rowIcon: { width: 40, height: 40, borderRadius: 12, backgroundColor: c.primarySoft, alignItems: 'center', justifyContent: 'center' },
    rowBody: { flex: 1, gap: 2 },
    rowName: { fontFamily: fonts.display, fontSize: 17, fontWeight: '700', color: c.text },
    rowSub: { fontSize: 13, color: c.muted },
    createRow: { flexDirection: 'row', gap: 8, alignItems: 'center', paddingHorizontal: 16, paddingBottom: 10 },
    input: { flex: 1, borderWidth: StyleSheet.hairlineWidth, borderColor: c.border, borderRadius: 12, paddingHorizontal: 14, paddingVertical: 12, fontSize: 15, color: c.text, backgroundColor: c.surface },
    createBtn: { paddingHorizontal: 16, paddingVertical: 12, borderRadius: 12, borderWidth: 1.5, borderColor: c.primary },
    createBtnText: { color: c.primary, fontWeight: '700', fontSize: 15 },
  });
