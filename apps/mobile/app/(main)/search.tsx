import { Ionicons } from '@expo/vector-icons';
import { FlashList } from '@shopify/flash-list';
import { router } from 'expo-router';
import { useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useSearch } from '@/api/hooks/useSearch';
import type { PlaceSummary, TagSummary, UserSummary } from '@/api/places';
import { type MessageKey, useT } from '@/i18n';
import { useFormat } from '@/lib/use-format';
import { useDebounced } from '@/lib/use-debounced';
import { fonts, type Palette, useColors } from '@/theme/colors';

type Row =
  | { type: 'header'; key: string; titleKey: MessageKey }
  | { type: 'place'; key: string; place: PlaceSummary }
  | { type: 'user'; key: string; user: UserSummary }
  | { type: 'tag'; key: string; tag: TagSummary };

export default function SearchScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const [q, setQ] = useState('');
  const debouncedQ = useDebounced(q, 300);
  const typed = q.trim(); // immediate — drives which branch shows
  const searched = debouncedQ.trim(); // debounced — drives the query
  const caughtUp = searched === typed;
  const { data, isFetching, isError } = useSearch(debouncedQ);

  const rows = useMemo<Row[]>(() => {
    if (!data) return [];
    const out: Row[] = [];
    if (data.places.length > 0) {
      out.push({ type: 'header', key: 'h-places', titleKey: 'search.section.places' });
      data.places.forEach((place) => out.push({ type: 'place', key: `p-${place.id}`, place }));
    }
    if (data.users.length > 0) {
      out.push({ type: 'header', key: 'h-people', titleKey: 'search.section.people' });
      data.users.forEach((user) => out.push({ type: 'user', key: `u-${user.id}`, user }));
    }
    if (data.tags.length > 0) {
      out.push({ type: 'header', key: 'h-tags', titleKey: 'search.section.tags' });
      data.tags.forEach((tag) => out.push({ type: 'tag', key: `t-${tag.id}`, tag }));
    }
    return out;
  }, [data]);

  // Only "no results" once the debounce has caught up to the box and the query
  // has settled — never while a query is still in flight or mid-debounce.
  const showEmpty = typed.length >= 2 && caughtUp && !isFetching && rows.length === 0 && !isError;

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <View style={styles.searchRow}>
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
      </View>

      {typed.length < 2 ? (
        <View style={styles.hint}>
          <Text style={styles.hintText}>{t('search.hint')}</Text>
        </View>
      ) : isError ? (
        <View style={styles.hint}>
          <Text style={styles.hintText}>{t('search.error')}</Text>
        </View>
      ) : showEmpty ? (
        <View style={styles.hint}>
          <Text style={styles.hintText}>{t('search.noResults', { query: typed })}</Text>
        </View>
      ) : (
        <FlashList
          data={rows}
          keyExtractor={(row) => row.key}
          getItemType={(row) => row.type}
          keyboardShouldPersistTaps="handled"
          contentContainerStyle={styles.list}
          renderItem={({ item }) => <RowView row={item} styles={styles} c={c} />}
          ListHeaderComponent={
            isFetching || !caughtUp ? <ActivityIndicator style={styles.loading} color={c.primary} /> : null
          }
        />
      )}
    </SafeAreaView>
  );
}

function RowView({ row, styles, c }: { row: Row; styles: Styles; c: Palette }) {
  const t = useT();
  const fmt = useFormat();
  if (row.type === 'header') {
    return <Text style={styles.section}>{t(row.titleKey)}</Text>;
  }
  if (row.type === 'place') {
    const p = row.place;
    const line = fmt.priceLine(p.category, p.price_range);
    return (
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={p.name}
        onPress={() => router.push({ pathname: '/place/[slug]', params: { slug: p.slug } })}
        style={({ pressed }) => [styles.row, pressed && styles.pressed]}
      >
        <Ionicons name="location-outline" size={20} color={c.muted} />
        <View style={styles.rowBody}>
          <Text style={styles.rowTitle} numberOfLines={1}>
            {p.name}
          </Text>
          <Text style={styles.rowSub} numberOfLines={1}>
            {[line, p.city].filter(Boolean).join(' · ')}
          </Text>
        </View>
      </Pressable>
    );
  }
  if (row.type === 'tag') {
    // Prefer the server-localized label (ADR-084) so a Spanish search shows the
    // Spanish name; fall back to the locale-formatted English name.
    const label = row.tag.label ?? fmt.tag(row.tag.name);
    return (
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={label}
        onPress={() => router.push({ pathname: '/tag/[slug]', params: { slug: row.tag.slug } })}
        style={({ pressed }) => [styles.row, pressed && styles.pressed]}
      >
        <Ionicons name="pricetag-outline" size={20} color={c.muted} />
        <Text style={styles.rowTitle}>{label}</Text>
      </Pressable>
    );
  }
  // A person (public profile) → tap through to their profile (T-077).
  const u = row.user;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={u.name ?? `@${u.username}`}
      onPress={() => router.push({ pathname: '/users/[username]', params: { username: u.username } })}
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
    >
      <Ionicons name="person-circle-outline" size={22} color={c.muted} />
      <View style={styles.rowBody}>
        <Text style={styles.rowTitle} numberOfLines={1}>
          {u.name ?? `@${u.username}`}
        </Text>
        <Text style={styles.handle} numberOfLines={1}>
          @{u.username}
        </Text>
      </View>
    </Pressable>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    searchRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingVertical: 10 },
    inputWrap: {
      flex: 1,
      flexDirection: 'row',
      alignItems: 'center',
      gap: 8,
      backgroundColor: c.surface,
      borderRadius: 12,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      paddingHorizontal: 12,
      height: 44,
    },
    input: { flex: 1, fontSize: 16, color: c.text },
    hint: { flex: 1, alignItems: 'center', paddingTop: 60 },
    hintText: { color: c.muted, fontSize: 15 },
    loading: { paddingVertical: 16 },
    list: { paddingHorizontal: 16, paddingBottom: 24 },
    section: { fontSize: 13, fontWeight: '700', color: c.muted, textTransform: 'uppercase', paddingTop: 16, paddingBottom: 6 },
    row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12 },
    pressed: { opacity: 0.6 },
    rowBody: { flex: 1 },
    rowTitle: { fontFamily: fonts.display, fontSize: 16, color: c.text, fontWeight: '700' },
    rowSub: { fontSize: 13, color: c.muted, textTransform: 'capitalize' },
    handle: { fontSize: 13, color: c.muted },
  });
