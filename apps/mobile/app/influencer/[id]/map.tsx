import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMemo } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import MapView, { Marker, PROVIDER_DEFAULT } from 'react-native-maps';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useInfluencer, useInfluencerMap } from '@/api/hooks/useInfluencer';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
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

  const { data: pins, isLoading } = useInfluencerMap(id ?? null);
  const { data: profile } = useInfluencer(id ?? null);

  const region = useMemo(() => fitRegion(pins ?? []), [pins]);

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader title={profile?.profile ? `@${profile.profile.handle}` : ''} />

      {isLoading ? (
        <ActivityIndicator color={c.primary} style={styles.loading} />
      ) : !region ? (
        <View style={styles.empty} testID="influencer-map-empty">
          <Ionicons name="map-outline" size={40} color={c.muted} />
          <Text style={styles.emptyText}>{t('influencer.noPlaces')}</Text>
        </View>
      ) : (
        <MapView provider={PROVIDER_DEFAULT} style={styles.map} initialRegion={region} showsPointsOfInterests={false}>
          {(pins ?? []).map((p) => (
            <Marker
              key={p.id}
              coordinate={{ latitude: p.lat, longitude: p.lng }}
              title={p.name}
              description={p.city ?? undefined}
              // MapPin carries no slug — the pin's numeric id is passed as the
              // `slug` param, which the API's route binding resolves (it accepts
              // either). Same convention as the main map.
              onCalloutPress={() => router.push({ pathname: '/place/[slug]', params: { slug: p.id } })}
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
