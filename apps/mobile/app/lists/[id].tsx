import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMemo, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, Share, StyleSheet, Text, View } from 'react-native';
import MapView, { Marker, PROVIDER_DEFAULT } from 'react-native-maps';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useDeleteList, useList, useUpdateList } from '@/api/hooks/useLists';
import type { PlaceListSummary } from '@/api/lists';
import { AddPlaceToListSheet } from '@/components/place/add-to-list-search';
import { useT } from '@/i18n';
import { listShareUrl, listWebUrl } from '@/lib/directions';
import { fitRegion } from '@/lib/map-region';
import { useFormat } from '@/lib/use-format';
import { fonts, type Palette, useColors } from '@/theme/colors';

export default function ListDetailScreen() {
  const { id, name } = useLocalSearchParams<{ id: string; name?: string }>();
  const c = useColors();
  const t = useT();
  const fmt = useFormat();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { data: list, isLoading } = useList(id ?? null);
  const del = useDeleteList();
  const update = useUpdateList();
  const [addOpen, setAddOpen] = useState(false);

  const openShareSheet = (publicSlug: string, listName: string) => {
    const deepLink = listShareUrl(publicSlug);
    // Prefer the web URL for the message (universal), falling back to the deep
    // link — Android drops `url` and only surfaces `message`, so the message
    // must always carry a link.
    const link = listWebUrl(publicSlug) ?? deepLink;
    void Share.share({
      message: `${t('shareList.message', { name: listName })}\n${link}`,
      url: deepLink,
    });
  };

  // Share = publish-if-needed, then open the OS sheet with the shareable link.
  const onShare = () => {
    if (!list) return;
    if (list.is_public && list.public_slug) {
      openShareSheet(list.public_slug, list.name);
      return;
    }
    update.mutate(
      { id: id as string, is_public: true },
      {
        onSuccess: (updated: PlaceListSummary) => {
          if (updated.public_slug) openShareSheet(updated.public_slug, updated.name);
        },
        onError: () => Alert.alert(t('shareList.error')),
      },
    );
  };

  const items = useMemo(() => list?.items ?? [], [list?.items]);
  const region = useMemo(() => fitRegion(items.map((i) => i.place)), [items]);
  const memberIds = useMemo(() => new Set(items.map((i) => i.place.id)), [items]);

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
          <View style={styles.headerActions}>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('save.addPlace')}
              onPress={() => setAddOpen(true)}
              hitSlop={12}
            >
              <Ionicons name="add-circle-outline" size={24} color={c.primary} />
            </Pressable>
            {items.length > 0 ? (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t('lists.viewOnMap')}
                onPress={() =>
                  router.push({ pathname: '/(main)/map', params: { list: id as string, listName: list.name } })
                }
                hitSlop={12}
              >
                <Ionicons name="map-outline" size={22} color={c.primary} />
              </Pressable>
            ) : null}
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('lists.share')}
              onPress={onShare}
              disabled={update.isPending}
              hitSlop={12}
            >
              <Ionicons name="share-outline" size={22} color={update.isPending ? c.muted : c.primary} />
            </Pressable>
            <Pressable accessibilityRole="button" accessibilityLabel={t('lists.delete')} onPress={onDelete} hitSlop={12}>
              <Ionicons name="trash-outline" size={22} color={c.danger} />
            </Pressable>
          </View>
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
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t('save.addPlace')}
            onPress={() => setAddOpen(true)}
            style={({ pressed }) => [styles.emptyCta, pressed && styles.pressed]}
          >
            <Ionicons name="add" size={18} color={c.onPrimary} />
            <Text style={styles.emptyCtaText}>{t('save.addPlace')}</Text>
          </Pressable>
        </View>
      ) : (
        <ScrollView contentContainerStyle={styles.scroll}>
          {region ? (
            <MapView
              style={styles.map}
              provider={PROVIDER_DEFAULT}
              initialRegion={region}
              showsPointsOfInterests={false}
            >
              {items.map((i) => (
                <Marker
                  key={i.place.id}
                  coordinate={{ latitude: i.place.lat, longitude: i.place.lng }}
                  pinColor={c.primary}
                  title={i.place.name}
                  onCalloutPress={() => router.push({ pathname: '/place/[slug]', params: { slug: i.place.slug } })}
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

      {addOpen && id ? (
        <AddPlaceToListSheet visible onClose={() => setAddOpen(false)} listId={id} memberIds={memberIds} />
      ) : null}
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, paddingHorizontal: 16, paddingVertical: 12 },
    title: { flex: 1, fontSize: 22, fontWeight: '700', color: c.text },
    headerActions: { flexDirection: 'row', alignItems: 'center', gap: 18 },
    spacer: { width: 22 },
    loading: { paddingVertical: 40 },
    empty: { alignItems: 'center', gap: 10, paddingTop: 80, paddingHorizontal: 40 },
    emptyText: { fontSize: 15, color: c.muted, textAlign: 'center' },
    emptyCta: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 6,
      marginTop: 8,
      backgroundColor: c.primary,
      paddingHorizontal: 18,
      paddingVertical: 11,
      borderRadius: 999,
    },
    emptyCtaText: { color: c.onPrimary, fontSize: 15, fontWeight: '700' },
    scroll: { padding: 16, gap: 4 },
    map: { height: 200, borderRadius: 16, overflow: 'hidden', marginBottom: 12 },
    row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 12, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: c.border },
    pressed: { opacity: 0.6 },
    rowBody: { flex: 1, gap: 2 },
    rowName: { fontFamily: fonts.display, fontSize: 16, fontWeight: '700', color: c.text },
    rowSub: { fontSize: 13, color: c.muted },
    note: { fontSize: 13, color: c.ink2, marginTop: 2 },
  });
