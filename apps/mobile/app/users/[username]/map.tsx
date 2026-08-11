import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useCallback, useMemo } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import MapView, { PROVIDER_DEFAULT } from 'react-native-maps';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useUserPlaces } from '@/api/hooks/useProfile';
import { PlaceMarker } from '@/components/map/place-marker';
import { fitRegion } from '@/lib/map-region';
import { useT } from '@/i18n';
import { type Palette, useColors } from '@/theme/colors';
import { ScreenHeader } from '@/components/screen-header';

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

  // Stable identity: PlaceMarker is memoized on `onPress`, so an inline arrow
  // would rebuild every marker on every render.
  const openPlace = useCallback(
    (slug: string) => router.push({ pathname: '/place/[slug]', params: { slug } }),
    [],
  );
  const { data: places, isLoading } = useUserPlaces(username ?? null);

  const region = useMemo(() => fitRegion(places ?? []), [places]);

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader title={username ? `@${username}` : ''} />

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
          {/* PlaceMarker, not a bare <Marker>: every map in the app draws the
              same pin. This screen had its own default red one — five surfaces
              had, against the main map's photo pin — because PlaceMarker used
              to require a viewport `MapPin` and these are fed by list
              endpoints. It now takes the fields both shapes share. */}
          {(places ?? []).map((p) => (
            <PlaceMarker
              key={p.id}
              pin={p}
              selected={false}
              // Always the detailed photo pin: these maps fit a handful of places
              // to bounds, which is the main map's zoomed-in state.
              detailed
              onPress={openPlace}
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
    loading: { paddingVertical: 40 },
    map: { flex: 1 },
    empty: { alignItems: 'center', gap: 10, paddingTop: 80, paddingHorizontal: 40 },
    emptyText: { fontSize: 15, color: c.muted, textAlign: 'center' },
  });
