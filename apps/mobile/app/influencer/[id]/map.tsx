import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useCallback, useMemo } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import MapView, { PROVIDER_DEFAULT } from 'react-native-maps';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useInfluencer, useInfluencerPlaces } from '@/api/hooks/useInfluencer';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
import { PlaceMarker } from '@/components/map/place-marker';
import { fitRegion } from '@/lib/map-region';
import { type Palette, useColors } from '@/theme/colors';
import { space, type } from '@/theme/tokens';

/**
 * An influencer's map (T-036/T-039): every place traceable to their posts, on a
 * fit-to-bounds MapView. Read-only and deliberately not the main map — no
 * filters, no viewport fetching, no personal scope. It answers one question
 * ("where has this person actually been?") and nothing else.
 */
export default function InfluencerMapScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  // Stable identity: PlaceMarker is memoized on `onPress`, so an inline arrow
  // would rebuild every marker on every render.
  const openPlace = useCallback(
    (slug: string) => router.push({ pathname: '/place/[slug]', params: { slug } }),
    [],
  );

  const { data: places, isLoading, isError } = useInfluencerPlaces(id ?? null);
  const { data: profile } = useInfluencer(id ?? null);

  const region = useMemo(() => fitRegion(places ?? []), [places]);

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader title={profile?.profile ? `@${profile.profile.handle}` : ''} />

      {isLoading ? (
        <ActivityIndicator color={c.primary} style={styles.loading} />
      ) : isError ? (
        // Distinguished from "no places" ON PURPOSE. Rendering the empty state
        // for a failed request is how a 422'd endpoint spent this screen's
        // whole life telling people a creator had no places — a definite claim
        // about their data, made from no data at all.
        <View style={styles.empty} testID="influencer-map-error">
          <Ionicons name="cloud-offline-outline" size={40} color={c.muted} />
          <Text style={styles.emptyText}>{t('common.error.general')}</Text>
        </View>
      ) : !region ? (
        <View style={styles.empty} testID="influencer-map-empty">
          <Ionicons name="map-outline" size={40} color={c.muted} />
          <Text style={styles.emptyText}>{t('influencer.noPlaces')}</Text>
        </View>
      ) : (
        <MapView provider={PROVIDER_DEFAULT} style={styles.map} initialRegion={region} showsPointsOfInterests={false}>
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
    loading: { paddingVertical: space.xl },
    map: { flex: 1 },
    empty: { alignItems: 'center', gap: space.xs, paddingTop: space.xxl, paddingHorizontal: space.xl },
    emptyText: { ...type.body, color: c.muted, textAlign: 'center' },
  });
