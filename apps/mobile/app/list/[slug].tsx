import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMemo } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import MapView, { Marker, PROVIDER_DEFAULT } from 'react-native-maps';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useCopyList, usePublicList } from '@/api/hooks/useLists';
import { useT } from '@/i18n';
import { fitRegion } from '@/lib/map-region';
import { useFormat } from '@/lib/use-format';
import { useSessionStore } from '@/stores/session';
import { fonts, type Palette, useColors } from '@/theme/colors';

/**
 * Read-only view of a shared list (T-063), reached by the deep link
 * `reelmap://list/{public_slug}` or the web `/l/{slug}` → app. Public: works for
 * guests. An authed viewer who isn't the owner gets a "Save a copy" action.
 */
export default function SharedListScreen() {
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const c = useColors();
  const t = useT();
  const fmt = useFormat();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { data: list, isLoading, isError } = usePublicList(slug ?? null);
  const me = useSessionStore((s) => s.user);
  const authed = useSessionStore((s) => s.status === 'authed');
  const copy = useCopyList();

  const items = useMemo(() => list?.items ?? [], [list?.items]);
  const region = useMemo(() => fitRegion(items.map((i) => i.place)), [items]);
  const isOwner = !!(me && list?.owner && list.owner.id === me.id);

  const onSaveCopy = () => {
    if (!slug) return;
    copy.mutate(slug, {
      onSuccess: (created) => router.replace({ pathname: '/lists/[id]', params: { id: created.id, name: created.name } }),
      onError: () => Alert.alert(t('publicList.copyError')),
    });
  };

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.header}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('place.back')}
          onPress={() => (router.canGoBack() ? router.back() : router.replace('/(main)/map'))}
          hitSlop={12}
        >
          <Ionicons name="chevron-back" size={26} color={c.text} />
        </Pressable>
        <Text style={styles.title} numberOfLines={1}>
          {list?.name ?? ''}
        </Text>
        <View style={styles.spacer} />
      </View>

      {isLoading ? (
        <ActivityIndicator color={c.primary} style={styles.loading} />
      ) : isError || !list ? (
        <View style={styles.empty}>
          <Ionicons name="lock-closed-outline" size={40} color={c.muted} />
          <Text style={styles.emptyTitle}>{t('publicList.notFound.title')}</Text>
          <Text style={styles.emptyText}>{t('publicList.notFound.body')}</Text>
        </View>
      ) : (
        <ScrollView contentContainerStyle={styles.scroll}>
          {list.owner ? (
            <View style={styles.ownerRow}>
              <Ionicons name="person-circle-outline" size={20} color={c.muted} />
              <Text style={styles.ownerText}>{t('publicList.sharedBy', { username: list.owner.username })}</Text>
            </View>
          ) : null}

          {authed && !isOwner ? (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('publicList.saveCopy')}
              onPress={onSaveCopy}
              disabled={copy.isPending}
              style={({ pressed }) => [styles.saveCta, (pressed || copy.isPending) && styles.pressed]}
            >
              {copy.isPending ? (
                <ActivityIndicator color={c.onPrimary} />
              ) : (
                <>
                  <Ionicons name="bookmark-outline" size={18} color={c.onPrimary} />
                  <Text style={styles.saveCtaText}>{t('publicList.saveCopy')}</Text>
                </>
              )}
            </Pressable>
          ) : null}

          {region ? (
            <MapView style={styles.map} provider={PROVIDER_DEFAULT} initialRegion={region} showsPointsOfInterests={false}>
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

          {items.length === 0 ? (
            <Text style={styles.emptyText}>{t('publicList.empty')}</Text>
          ) : (
            items.map((i) => (
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
                </View>
                <Ionicons name="chevron-forward" size={18} color={c.muted} />
              </Pressable>
            ))
          )}
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    header: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'space-between',
      gap: 12,
      paddingHorizontal: 16,
      paddingVertical: 12,
    },
    title: { flex: 1, fontSize: 22, fontWeight: '700', color: c.text },
    spacer: { width: 26 },
    loading: { paddingVertical: 40 },
    empty: { alignItems: 'center', gap: 10, paddingTop: 80, paddingHorizontal: 40 },
    emptyTitle: { fontSize: 17, fontWeight: '700', color: c.text },
    emptyText: { fontSize: 15, color: c.muted, textAlign: 'center' },
    scroll: { padding: 16, gap: 4 },
    ownerRow: { flexDirection: 'row', alignItems: 'center', gap: 6, marginBottom: 12 },
    ownerText: { fontSize: 14, color: c.muted },
    saveCta: {
      flexDirection: 'row',
      alignItems: 'center',
      justifyContent: 'center',
      gap: 8,
      backgroundColor: c.primary,
      paddingVertical: 12,
      borderRadius: 999,
      marginBottom: 16,
    },
    saveCtaText: { color: c.onPrimary, fontSize: 15, fontWeight: '700' },
    map: { height: 200, borderRadius: 16, overflow: 'hidden', marginBottom: 12 },
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
  });
