import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMemo, useState } from 'react';
import { Linking, Pressable, ScrollView, Share, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { usePlace } from '@/api/hooks/usePlace';
import type { PlaceDetail } from '@/api/places';
import { Chip } from '@/components/place/chip';
import { MiniMap } from '@/components/place/mini-map';
import { SourceCard } from '@/components/place/source-card';
import { cuisinePriceLine } from '@/lib/format';
import { summarizeHours } from '@/lib/opening-hours';
import { directionsUrl, placeShareUrl } from '@/lib/directions';
import { type Palette, useColors } from '@/theme/colors';

export default function PlaceDetailScreen() {
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { data: place, isLoading, isError, refetch } = usePlace(slug ?? '');

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <Header onBack={() => (router.canGoBack() ? router.back() : router.replace('/(main)/map'))} styles={styles} c={c} />
      {isLoading ? (
        <PlaceSkeleton styles={styles} />
      ) : isError || !place ? (
        <ErrorState styles={styles} c={c} onRetry={() => void refetch()} />
      ) : (
        <PlaceBody place={place} styles={styles} c={c} />
      )}
    </SafeAreaView>
  );
}

function Header({ onBack, styles, c }: { onBack: () => void; styles: Styles; c: Palette }) {
  return (
    <View style={styles.header}>
      <Pressable accessibilityRole="button" accessibilityLabel="Go back" onPress={onBack} hitSlop={12}>
        <Ionicons name="chevron-back" size={26} color={c.text} />
      </Pressable>
    </View>
  );
}

function PlaceBody({ place, styles, c }: { place: PlaceDetail; styles: Styles; c: Palette }) {
  const [hoursOpen, setHoursOpen] = useState(false);
  const hours = useMemo(() => summarizeHours(place.opening_hours), [place.opening_hours]);
  const tags = useMemo(
    () => Array.from(new Set([...place.cuisines, ...place.vibe_tags, ...place.dietary_tags])),
    [place.cuisines, place.vibe_tags, place.dietary_tags],
  );

  const openMap = () =>
    router.push({ pathname: '/(main)/map', params: { lat: String(place.lat), lng: String(place.lng) } });

  const openDirections = () => void Linking.openURL(directionsUrl(place.lat, place.lng, place.name));

  const share = () =>
    void Share.share({ message: `${place.name} on Reelmap`, url: placeShareUrl(place.slug) });

  return (
    <ScrollView contentContainerStyle={styles.scroll} showsVerticalScrollIndicator={false}>
      {/* Header block */}
      <View style={styles.block}>
        <Text style={styles.name}>{place.name}</Text>
        <View style={styles.metaRow}>
          {cuisinePriceLine(place.category, place.price_range) ? (
            <Text style={styles.meta}>{cuisinePriceLine(place.category, place.price_range)}</Text>
          ) : null}
          {place.rating.google.value != null ? (
            <Text style={styles.rating}>
              <Ionicons name="star" size={13} color="#F5A623" /> {place.rating.google.value.toFixed(1)}
              {place.rating.google.count > 0 ? ` (${place.rating.google.count})` : ''}
            </Text>
          ) : null}
          <Text style={styles.metaMuted}>
            {place.source_count} source{place.source_count === 1 ? '' : 's'}
          </Text>
        </View>
        {tags.length > 0 ? (
          <View style={styles.chips}>
            {tags.map((t) => (
              <Chip key={t} label={t} />
            ))}
          </View>
        ) : null}
      </View>

      {/* Info: address / hours / phone / website */}
      <View style={styles.block}>
        {place.address ? (
          <Row icon="location-outline" c={c} styles={styles}>
            <Text style={styles.rowText}>{place.address}</Text>
          </Row>
        ) : null}

        {hours.label ? (
          <Pressable onPress={() => setHoursOpen((v) => !v)} disabled={hours.weekly.length === 0}>
            <Row icon="time-outline" c={c} styles={styles}>
              <Text style={[styles.rowText, hours.openNow ? styles.open : styles.closed]}>{hours.label}</Text>
              {hours.weekly.length > 0 ? (
                <Ionicons
                  name={hoursOpen ? 'chevron-up' : 'chevron-down'}
                  size={16}
                  color={c.muted}
                  style={styles.chevron}
                />
              ) : null}
            </Row>
          </Pressable>
        ) : null}
        {hoursOpen ? (
          <View style={styles.weekly}>
            {hours.weekly.map((line) => (
              <Text key={line} style={styles.weeklyLine}>
                {line}
              </Text>
            ))}
          </View>
        ) : null}

        {place.phone ? (
          <Pressable onPress={() => void Linking.openURL(`tel:${place.phone}`)}>
            <Row icon="call-outline" c={c} styles={styles}>
              <Text style={[styles.rowText, styles.link]}>{place.phone}</Text>
            </Row>
          </Pressable>
        ) : null}

        {place.website ? (
          <Pressable onPress={() => void Linking.openURL(place.website!)}>
            <Row icon="globe-outline" c={c} styles={styles}>
              <Text style={[styles.rowText, styles.link]} numberOfLines={1}>
                {place.website.replace(/^https?:\/\//, '')}
              </Text>
            </Row>
          </Pressable>
        ) : null}
      </View>

      {/* Mini-map */}
      <View style={styles.block}>
        <MiniMap lat={place.lat} lng={place.lng} onPress={openMap} />
      </View>

      {/* Actions */}
      <View style={styles.actions}>
        <ActionButton icon="navigate" label="Directions" onPress={openDirections} c={c} styles={styles} />
        <ActionButton icon="share-outline" label="Share" onPress={share} c={c} styles={styles} />
      </View>

      {/* Dishes */}
      {place.dishes.length > 0 ? (
        <View style={styles.block}>
          <Text style={styles.sectionTitle}>Dishes</Text>
          <View style={styles.chips}>
            {place.dishes.map((d) => (
              <Chip key={d.name} label={d.name} />
            ))}
          </View>
        </View>
      ) : null}

      {/* Sources */}
      {place.sources && place.sources.length > 0 ? (
        <View style={styles.block}>
          <Text style={styles.sectionTitle}>Where it came from</Text>
          <View style={styles.sourceList}>
            {place.sources.map((s) => (
              <SourceCard key={s.id} source={s} />
            ))}
          </View>
        </View>
      ) : null}

      <View style={styles.footer} />
    </ScrollView>
  );
}

function Row({
  icon,
  c,
  styles,
  children,
}: {
  icon: keyof typeof Ionicons.glyphMap;
  c: Palette;
  styles: Styles;
  children: React.ReactNode;
}) {
  return (
    <View style={styles.row}>
      <Ionicons name={icon} size={18} color={c.muted} style={styles.rowIcon} />
      <View style={styles.rowBody}>{children}</View>
    </View>
  );
}

function ActionButton({
  icon,
  label,
  onPress,
  c,
  styles,
}: {
  icon: keyof typeof Ionicons.glyphMap;
  label: string;
  onPress: () => void;
  c: Palette;
  styles: Styles;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      onPress={onPress}
      style={({ pressed }) => [styles.action, pressed ? styles.actionPressed : null]}
    >
      <Ionicons name={icon} size={20} color={c.onPrimary} />
      <Text style={styles.actionLabel}>{label}</Text>
    </Pressable>
  );
}

function PlaceSkeleton({ styles }: { styles: Styles }) {
  return (
    <View style={styles.scroll} testID="place-skeleton">
      <View style={[styles.skelBlock, { height: 28, width: '70%' }]} />
      <View style={[styles.skelBlock, { height: 16, width: '45%' }]} />
      <View style={[styles.skelBlock, { height: 160, borderRadius: 16 }]} />
      <View style={[styles.skelBlock, { height: 90, borderRadius: 16 }]} />
    </View>
  );
}

function ErrorState({ styles, c, onRetry }: { styles: Styles; c: Palette; onRetry: () => void }) {
  return (
    <View style={styles.center}>
      <Ionicons name="sad-outline" size={40} color={c.muted} />
      <Text style={styles.errorTitle}>Place not found</Text>
      <Text style={styles.errorBody}>It may have been removed or the link is out of date.</Text>
      <Pressable accessibilityRole="button" onPress={onRetry} style={styles.retry}>
        <Text style={styles.retryText}>Try again</Text>
      </Pressable>
    </View>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    header: { paddingHorizontal: 16, paddingVertical: 8 },
    scroll: { padding: 20, gap: 20 },
    block: { gap: 10 },
    name: { fontSize: 26, fontWeight: '700', letterSpacing: -0.5, color: c.text },
    metaRow: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 12 },
    meta: { fontSize: 15, color: c.text, textTransform: 'capitalize' },
    metaMuted: { fontSize: 14, color: c.muted },
    rating: { fontSize: 14, color: c.text, fontWeight: '600' },
    chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
    row: { flexDirection: 'row', alignItems: 'flex-start', gap: 12, paddingVertical: 6 },
    rowIcon: { marginTop: 1 },
    rowBody: { flex: 1, flexDirection: 'row', alignItems: 'center' },
    rowText: { flex: 1, fontSize: 15, color: c.text, lineHeight: 21 },
    chevron: { marginLeft: 8 },
    open: { color: '#16A34A', fontWeight: '600' },
    closed: { color: c.danger, fontWeight: '600' },
    weekly: { paddingLeft: 30, gap: 4, paddingBottom: 4 },
    weeklyLine: { fontSize: 14, color: c.muted },
    link: { color: c.primary },
    actions: { flexDirection: 'row', gap: 12 },
    action: {
      flex: 1,
      flexDirection: 'row',
      gap: 8,
      alignItems: 'center',
      justifyContent: 'center',
      backgroundColor: c.primary,
      borderRadius: 14,
      paddingVertical: 14,
    },
    actionPressed: { backgroundColor: c.primaryPressed },
    actionLabel: { color: c.onPrimary, fontSize: 15, fontWeight: '600' },
    sectionTitle: { fontSize: 18, fontWeight: '700', color: c.text },
    sourceList: { gap: 12 },
    footer: { height: 24 },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 32, gap: 8 },
    errorTitle: { fontSize: 20, fontWeight: '700', color: c.text, marginTop: 8 },
    errorBody: { fontSize: 15, color: c.muted, textAlign: 'center' },
    retry: {
      marginTop: 12,
      paddingHorizontal: 20,
      paddingVertical: 10,
      borderRadius: 12,
      borderWidth: 1.5,
      borderColor: c.primary,
    },
    retryText: { color: c.primary, fontWeight: '600', fontSize: 15 },
    skelBlock: { backgroundColor: c.surface, borderRadius: 8, marginBottom: 16 },
  });
