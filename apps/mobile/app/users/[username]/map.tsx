import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMemo } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import MapView, { Marker, PROVIDER_DEFAULT } from 'react-native-maps';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useUserPlaces } from '@/api/hooks/useProfile';
import { fitRegion } from '@/lib/map-region';
import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';

/**
 * A user's map (T-071) — their published places on a fit-to-bounds MapView.
 * Reached from their profile; never mixed with my own collection. Powered by
 * the same GET /users/{username}/places data as their profile list.
 */
export default function UserMapScreen() {
  const { username } = useLocalSearchParams<{ username: string }>();
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { data: places, isLoading } = useUserPlaces(username ?? null);

  const region = useMemo(() => fitRegion(places ?? []), [places]);

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
        <Text style={styles.headerTitle} numberOfLines={1}>
          {username ? `@${username}` : ''}
        </Text>
        <View style={styles.spacer} />
      </View>

      {isLoading ? (
        <ActivityIndicator color={c.primary} style={styles.loading} />
      ) : !region ? (
        <View style={styles.empty}>
          <Ionicons name="map-outline" size={40} color={c.muted} />
          <Text style={styles.emptyText}>{t('profileUser.noPlaces')}</Text>
        </View>
      ) : (
        <MapView
          provider={PROVIDER_DEFAULT}
          style={styles.map}
          initialRegion={region}
          showsPointsOfInterests={false}
        >
          {(places ?? []).map((p) => (
            <Marker
              key={p.id}
              coordinate={{ latitude: p.lat, longitude: p.lng }}
              title={p.name}
              description={p.city ?? undefined}
              onCalloutPress={() => router.push({ pathname: '/place/[slug]', params: { slug: p.slug } })}
            />
          ))}
        </MapView>
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
    headerTitle: { flex: 1, fontSize: 18, fontWeight: '700', color: c.text },
    spacer: { width: 26 },
    loading: { paddingVertical: 40 },
    map: { flex: 1 },
    empty: { alignItems: 'center', gap: 10, paddingTop: 80, paddingHorizontal: 40 },
    emptyText: { fontSize: 15, color: c.muted, textAlign: 'center' },
  });
