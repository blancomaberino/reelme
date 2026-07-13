import { Ionicons } from '@expo/vector-icons';
import { FlashList } from '@shopify/flash-list';
import { router } from 'expo-router';
import { useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useSearch } from '@/api/hooks/useSearch';
import type { InfluencerSummary, PlaceSummary, TagSummary } from '@/api/places';
import { cuisinePriceLine } from '@/lib/format';
import { useDebounced } from '@/lib/use-debounced';
import { type Palette, useColors } from '@/theme/colors';

type Row =
  | { type: 'header'; key: string; title: string }
  | { type: 'place'; key: string; place: PlaceSummary }
  | { type: 'tag'; key: string; tag: TagSummary }
  | { type: 'influencer'; key: string; inf: InfluencerSummary };

export default function SearchScreen() {
  const c = useColors();
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
      out.push({ type: 'header', key: 'h-places', title: 'Places' });
      data.places.forEach((place) => out.push({ type: 'place', key: `p-${place.id}`, place }));
    }
    if (data.tags.length > 0) {
      out.push({ type: 'header', key: 'h-tags', title: 'Tags' });
      data.tags.forEach((tag) => out.push({ type: 'tag', key: `t-${tag.id}`, tag }));
    }
    if (data.influencers.length > 0) {
      out.push({ type: 'header', key: 'h-inf', title: 'Influencers' });
      data.influencers.forEach((inf) => out.push({ type: 'influencer', key: `i-${inf.id}`, inf }));
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
            placeholder="Search places, tags…"
            placeholderTextColor={c.placeholder}
            value={q}
            onChangeText={setQ}
            autoFocus
            autoCorrect={false}
            autoCapitalize="none"
            returnKeyType="search"
            accessibilityLabel="Search"
          />
          {q.length > 0 ? (
            <Pressable accessibilityLabel="Clear" onPress={() => setQ('')} hitSlop={8}>
              <Ionicons name="close-circle" size={18} color={c.placeholder} />
            </Pressable>
          ) : null}
        </View>
        <Pressable accessibilityRole="button" accessibilityLabel="Close" onPress={() => router.back()} hitSlop={8}>
          <Text style={styles.cancel}>Cancel</Text>
        </Pressable>
      </View>

      {typed.length < 2 ? (
        <View style={styles.hint}>
          <Text style={styles.hintText}>Type at least 2 characters to search.</Text>
        </View>
      ) : isError ? (
        <View style={styles.hint}>
          <Text style={styles.hintText}>Something went wrong. Try again.</Text>
        </View>
      ) : showEmpty ? (
        <View style={styles.hint}>
          <Text style={styles.hintText}>No results for “{typed}”.</Text>
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
  if (row.type === 'header') {
    return <Text style={styles.section}>{row.title}</Text>;
  }
  if (row.type === 'place') {
    const p = row.place;
    const line = cuisinePriceLine(p.category, p.price_range);
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
    return (
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={row.tag.name}
        onPress={() => router.push({ pathname: '/tag/[slug]', params: { slug: row.tag.slug } })}
        style={({ pressed }) => [styles.row, pressed && styles.pressed]}
      >
        <Ionicons name="pricetag-outline" size={20} color={c.muted} />
        <Text style={styles.rowTitle}>{row.tag.name}</Text>
      </Pressable>
    );
  }
  // Influencers are inert until M3 profiles (T-036/T-039).
  return (
    <View style={styles.row}>
      <Ionicons name="star-outline" size={20} color={c.muted} />
      <View style={styles.rowBody}>
        <Text style={styles.rowTitle} numberOfLines={1}>
          @{row.inf.handle}
        </Text>
        <Text style={styles.rowSub}>Profiles coming soon</Text>
      </View>
    </View>
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
    cancel: { color: c.primary, fontSize: 16, fontWeight: '600' },
    hint: { flex: 1, alignItems: 'center', paddingTop: 60 },
    hintText: { color: c.muted, fontSize: 15 },
    loading: { paddingVertical: 16 },
    list: { paddingHorizontal: 16, paddingBottom: 24 },
    section: { fontSize: 13, fontWeight: '700', color: c.muted, textTransform: 'uppercase', paddingTop: 16, paddingBottom: 6 },
    row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12 },
    pressed: { opacity: 0.6 },
    rowBody: { flex: 1 },
    rowTitle: { fontSize: 16, color: c.text, fontWeight: '600' },
    rowSub: { fontSize: 13, color: c.muted, textTransform: 'capitalize' },
  });
