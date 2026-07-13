import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMemo } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import MapView, { Marker, PROVIDER_DEFAULT, type Region } from 'react-native-maps';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useDeleteList, useList } from '@/api/hooks/useLists';
import type { PlaceListItem } from '@/api/lists';
import { useT } from '@/i18n';
import { useFormat } from '@/lib/use-format';
import { fonts, type Palette, useColors } from '@/theme/colors';

/** Region that fits all of a list's places (with padding). */
function fitRegion(items: PlaceListItem[]): Region | null {
  const pts = items.map((i) => i.place).filter((p) => Number.isFinite(p.lat) && Number.isFinite(p.lng));
  if (pts.length === 0) return null;
  const lats = pts.map((p) => p.lat);
  const lngs = pts.map((p) => p.lng);
  const minLat = Math.min(...lats);
  const maxLat = Math.max(...lats);
  const minLng = Math.min(...lngs);
  const maxLng = Math.max(...lngs);
  return {
    latitude: (minLat + maxLat) / 2,
    longitude: (minLng + maxLng) / 2,
    latitudeDelta: Math.max(0.02, (maxLat - minLat) * 1.4),
    longitudeDelta: Math.max(0.02, (maxLng - minLng) * 1.4),
  };
}

export default function ListDetailScreen() {
  const { id, name } = useLocalSearchParams<{ id: string; name?: string }>();
  const c = useColors();
  const t = useT();
  const fmt = useFormat();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { data: list, isLoading } = useList(id ?? null);
  const del = useDeleteList();

  const items = useMemo(() => list?.items ?? [], [list?.items]);
  const region = useMemo(() => fitRegion(items), [items]);

  const onDelete = () => {
    Alert.alert(t('lists.deleteConfirm.title'), t('lists.deleteConfirm.message', { name: list?.name ?? '' }), [
      { text: t('common.cancel'), style: 'cancel' },
      {
        text: t('lists.delete'),
        style: 'destructive',
        onPress: () => del.mutate(id as string, { onSuccess: () => router.back() }),
      },
    ]);
  };

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.header}>
        <Pressable accessibilityRole="button" accessibilityLabel={t('place.back')} onPress={() => router.back()} hitSlop={12}>
          <Ionicons name="chevron-back" size={26} color={c.text} />
        </Pressable>
        <Text style={styles.title} numberOfLines={1}>
          {list?.name ?? name ?? ''}
        </Text>
        {list ? (
          <Pressable accessibilityRole="button" accessibilityLabel={t('lists.delete')} onPress={onDelete} hitSlop={12}>
            <Ionicons name="trash-outline" size={22} color={c.danger} />
          </Pressable>
        ) : (
          <View style={styles.spacer} />
        )}
      </View>

      {isLoading ? (
        <ActivityIndicator color={c.primary} style={styles.loading} />
      ) : items.length === 0 ? (
        <View style={styles.empty}>
          <Ionicons name="location-outline" size={40} color={c.muted} />
          <Text style={styles.emptyText}>{t('lists.empty.places')}</Text>
        </View>
      ) : (
        <ScrollView contentContainerStyle={styles.scroll}>
          {region ? (
            <MapView
              style={styles.map}
              provider={PROVIDER_DEFAULT}
              initialRegion={region}
              showsPointsOfInterests={false}
              pointerEvents="none"
            >
              {items.map((i) => (
                <Marker
                  key={i.place.id}
                  coordinate={{ latitude: i.place.lat, longitude: i.place.lng }}
                  pinColor={c.primary}
                />
              ))}
            </MapView>
          ) : null}

          {items.map((i) => (
            <Pressable
              key={i.place.id}
              accessibilityRole="button"
              accessibilityLabel={i.place.name}
              onPress={() => router.push({ pathname: '/place/[slug]', params: { slug: i.place.slug } })}
              style={({ pressed }) => [styles.row, pressed && styles.pressed]}
            >
              <Ionicons name="location" size={20} color={c.primary} />
              <View style={styles.rowBody}>
                <Text style={styles.rowName} numberOfLines={1}>
                  {i.place.name}
                </Text>
                <Text style={styles.rowSub} numberOfLines={1}>
                  {[fmt.priceLine(i.place.category, i.place.price_range), i.place.city].filter(Boolean).join(' · ')}
                </Text>
                {i.note ? (
                  <Text style={styles.note} numberOfLines={2}>
                    {i.note}
                  </Text>
                ) : null}
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
    header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, paddingHorizontal: 16, paddingVertical: 12 },
    title: { flex: 1, fontSize: 22, fontWeight: '700', color: c.text },
    spacer: { width: 22 },
    loading: { paddingVertical: 40 },
    empty: { alignItems: 'center', gap: 10, paddingTop: 80, paddingHorizontal: 40 },
    emptyText: { fontSize: 15, color: c.muted, textAlign: 'center' },
    scroll: { padding: 16, gap: 4 },
    map: { height: 200, borderRadius: 16, overflow: 'hidden', marginBottom: 12 },
    row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: c.border },
    pressed: { opacity: 0.6 },
    rowBody: { flex: 1, gap: 2 },
    rowName: { fontFamily: fonts.display, fontSize: 16, fontWeight: '700', color: c.text },
    rowSub: { fontSize: 13, color: c.muted },
    note: { fontSize: 13, color: c.ink2, marginTop: 2 },
  });
