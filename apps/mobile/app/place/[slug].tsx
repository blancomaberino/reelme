import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMemo, useState } from 'react';
import { Pressable, ScrollView, Share, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { usePlace } from '@/api/hooks/usePlace';
import type { PlaceDetail } from '@/api/places';
import { Chip } from '@/components/place/chip';
import { MiniMap } from '@/components/place/mini-map';
import { ReviewComposer } from '@/components/place/review-composer';
import { MenuSheet } from '@/components/place/menu-sheet';
import { SaveToListSheet } from '@/components/place/save-to-list';
import { SourceCard } from '@/components/place/source-card';
import { Thumbnail } from '@/components/place/thumbnail';
import { useT } from '@/i18n';
import { useFormat } from '@/lib/use-format';
import { summarizeHours } from '@/lib/opening-hours';
import { directionsUrl, googleMapsUrl, placeShareUrl } from '@/lib/directions';
import { openExternal, openWebUrl } from '@/lib/linking';
import { useSessionStore } from '@/stores/session';
import { fonts, type Palette, useColors } from '@/theme/colors';

export default function PlaceDetailScreen() {
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);
  const { data: place, isLoading, isError, refetch } = usePlace(slug ?? '');
  const authed = useSessionStore((s) => s.status === 'authed');
  const [saveOpen, setSaveOpen] = useState(false);

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <Header
        onBack={() => (router.canGoBack() ? router.back() : router.replace('/(main)/map'))}
        onSave={authed && place ? () => setSaveOpen(true) : undefined}
        styles={styles}
        c={c}
      />
      {isLoading ? (
        <PlaceSkeleton styles={styles} />
      ) : isError || !place ? (
        <ErrorState styles={styles} c={c} onRetry={() => void refetch()} />
      ) : (
        <PlaceBody place={place} styles={styles} c={c} />
      )}
      {place ? <SaveToListSheet placeId={place.id} visible={saveOpen} onClose={() => setSaveOpen(false)} /> : null}
    </SafeAreaView>
  );
}

function Header({
  onBack,
  onSave,
  styles,
  c,
}: {
  onBack: () => void;
  onSave?: () => void;
  styles: Styles;
  c: Palette;
}) {
  const t = useT();
  return (
    <View style={styles.header}>
      <Pressable accessibilityRole="button" accessibilityLabel={t('place.back')} onPress={onBack} hitSlop={12}>
        <Ionicons name="chevron-back" size={26} color={c.text} />
      </Pressable>
      {onSave ? (
        <Pressable accessibilityRole="button" accessibilityLabel={t('save.title')} onPress={onSave} hitSlop={12}>
          <Ionicons name="bookmark-outline" size={24} color={c.text} />
        </Pressable>
      ) : null}
    </View>
  );
}

function PlaceBody({ place, styles, c }: { place: PlaceDetail; styles: Styles; c: Palette }) {
  const t = useT();
  const fmt = useFormat();
  const [hoursOpen, setHoursOpen] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const hours = useMemo(() => summarizeHours(place.opening_hours), [place.opening_hours]);
  const tags = useMemo(
    () => Array.from(new Set([...place.cuisines, ...place.vibe_tags, ...place.dietary_tags])),
    [place.cuisines, place.vibe_tags, place.dietary_tags],
  );
  // Hero picture from the reel: prefer the primary source, else the first.
  const heroUri = useMemo(() => {
    const s = place.sources?.find((x) => x.is_primary) ?? place.sources?.[0];
    return s?.source_post?.thumbnail_url ?? null;
  }, [place.sources]);
  const appReviews = place.reviews ?? [];
  const googleReviews = place.google_reviews ?? [];
  // The viewer's own review (prefills the composer); listed rows exclude it.
  const ownReview = appReviews.find((r) => r.is_own) ?? null;
  const otherReviews = appReviews.filter((r) => !r.is_own);

  const openMap = () =>
    router.push({ pathname: '/(main)/map', params: { lat: String(place.lat), lng: String(place.lng) } });

  const openDirections = () => void openExternal(directionsUrl(place.lat, place.lng, place.name));

  const share = () =>
    void Share.share({ message: t('place.shareMessage', { name: place.name }), url: placeShareUrl(place.slug) });

  return (
    <ScrollView contentContainerStyle={styles.scroll} showsVerticalScrollIndicator={false}>
      {/* Hero picture from the shared reel (full-bleed) */}
      {heroUri ? <Thumbnail uri={heroUri} style={styles.hero} testID="place-hero" /> : null}

      {/* Header block */}
      <View style={styles.block}>
        <Text style={styles.name}>{place.name}</Text>
        <View style={styles.metaRow}>
          {fmt.priceLine(place.category, place.price_range) ? (
            <Text style={styles.meta}>{fmt.priceLine(place.category, place.price_range)}</Text>
          ) : null}
          {place.rating.google.value != null ? (
            <Text style={styles.rating}>
              <Ionicons name="star" size={13} color={c.gold} /> {place.rating.google.value.toFixed(1)}
              {place.rating.google.count > 0 ? ` (${place.rating.google.count})` : ''}
            </Text>
          ) : null}
          <Text style={styles.metaMuted}>
            {t('place.sourceCount', { count: place.source_count })}
          </Text>
        </View>
        {tags.length > 0 ? (
          <View style={styles.chips}>
            {tags.map((tag) => (
              <Chip key={tag} label={fmt.tag(tag)} />
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
          <Pressable
            onPress={() => setHoursOpen((v) => !v)}
            disabled={hours.weekly.length === 0}
            accessibilityRole="button"
            accessibilityLabel={hoursOpen ? t('place.hoursHide') : t('place.hoursShow')}
            accessibilityState={{ expanded: hoursOpen }}
          >
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
          <Pressable
            onPress={() => void openExternal(`tel:${place.phone}`)}
            accessibilityRole="button"
            accessibilityLabel={t('place.call', { phone: place.phone })}
          >
            <Row icon="call-outline" c={c} styles={styles}>
              <Text style={[styles.rowText, styles.link]}>{place.phone}</Text>
            </Row>
          </Pressable>
        ) : null}

        {place.website ? (
          <Pressable onPress={() => openWebUrl(place.website)} accessibilityRole="link" accessibilityLabel={t('place.website')}>
            <Row icon="globe-outline" c={c} styles={styles}>
              <Text style={[styles.rowText, styles.link]} numberOfLines={1}>
                {place.website.replace(/^https?:\/\//, '')}
              </Text>
            </Row>
          </Pressable>
        ) : null}

        {googleMapsUrl(place.name, place.google_place_id) ? (
          <Pressable
            onPress={() => openWebUrl(googleMapsUrl(place.name, place.google_place_id))}
            accessibilityRole="link"
            accessibilityLabel={t('place.googleMaps')}
          >
            <Row icon="map-outline" c={c} styles={styles}>
              <Text style={[styles.rowText, styles.link]}>{t('place.googleMaps')}</Text>
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
        <ActionButton icon="navigate" label={t('place.directions')} onPress={openDirections} c={c} styles={styles} />
        <ActionButton icon="share-outline" label={t('place.share')} onPress={share} c={c} styles={styles} />
      </View>

      {/* Menu — a button into the full dish/price list + its source & date */}
      {place.dishes.length > 0 ? (
        <View style={styles.block}>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t('menu.view')}
            onPress={() => setMenuOpen(true)}
            style={({ pressed }) => [styles.menuButton, pressed && styles.menuButtonPressed]}
          >
            <Ionicons name="restaurant-outline" size={20} color={c.primary} />
            <Text style={styles.menuButtonText}>{t('menu.view')}</Text>
            <Text style={styles.menuCount}>{t('place.dishesCount', { count: place.dishes.length })}</Text>
            <Ionicons name="chevron-forward" size={18} color={c.muted} />
          </Pressable>
        </View>
      ) : null}

      {/* Sources */}
      {place.sources && place.sources.length > 0 ? (
        <View style={styles.block}>
          <Text style={styles.sectionTitle}>{t('place.sources')}</Text>
          <View style={styles.sourceList}>
            {place.sources.map((s) => (
              <SourceCard key={s.id} source={s} />
            ))}
          </View>
        </View>
      ) : null}

      {/* Reviews: your composer, then in-app + Google (with reviewer photos) */}
      <View style={styles.block}>
        <Text style={styles.sectionTitle}>{t('place.reviews')}</Text>
        <ReviewComposer placeId={place.id} slug={place.slug} own={ownReview} />
        {otherReviews.map((r) => (
          <ReviewRow
            key={`a-${r.id}`}
            name={r.author ? `@${r.author.username}` : t('place.anonymous')}
            rating={r.rating}
            text={r.body}
            c={c}
            styles={styles}
          />
        ))}
        {googleReviews.length > 0 ? (
            <>
              <Text style={styles.reviewSub}>{t('place.fromGoogle')}</Text>
              {googleReviews.map((r, i) => (
                <ReviewRow
                  key={`g-${i}`}
                  name={r.author ?? t('place.googleUser')}
                  rating={r.rating}
                  text={r.text}
                  photo={r.profile_photo_url}
                  c={c}
                  styles={styles}
                />
              ))}
            </>
          ) : null}
        </View>

      <View style={styles.footer} />

      <MenuSheet
        visible={menuOpen}
        onClose={() => setMenuOpen(false)}
        dishes={place.dishes}
        updatedAt={place.dishes_updated_at}
        sources={place.sources ?? []}
      />
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

function ReviewRow({
  name,
  suffix,
  rating,
  text,
  photo,
  c,
  styles,
}: {
  name: string;
  suffix?: string;
  rating: number | null;
  text: string | null;
  photo?: string | null;
  c: Palette;
  styles: Styles;
}) {
  const stars = rating != null ? '★'.repeat(Math.max(0, Math.min(5, Math.round(rating)))) : '';
  return (
    <View style={styles.review}>
      {photo ? (
        <Thumbnail uri={photo} style={styles.reviewAvatar} />
      ) : (
        <View style={[styles.reviewAvatar, styles.reviewAvatarFallback]}>
          <Text style={styles.reviewInitial}>{name.replace(/^@/, '').charAt(0).toUpperCase() || '?'}</Text>
        </View>
      )}
      <View style={styles.reviewBody}>
        <Text style={styles.reviewName} numberOfLines={1}>
          {name}
          {suffix} <Text style={styles.reviewStars}>{stars}</Text>
        </Text>
        {text ? <Text style={styles.reviewText}>{text}</Text> : null}
      </View>
    </View>
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
  const t = useT();
  return (
    <View style={styles.center}>
      <Ionicons name="sad-outline" size={40} color={c.muted} />
      <Text style={styles.errorTitle}>{t('place.notFound.title')}</Text>
      <Text style={styles.errorBody}>{t('place.notFound.body')}</Text>
      <Pressable accessibilityRole="button" onPress={onRetry} style={styles.retry}>
        <Text style={styles.retryText}>{t('common.tryAgain')}</Text>
      </Pressable>
    </View>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    header: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 16, paddingVertical: 8 },
    scroll: { padding: 20, gap: 20 },
    block: { gap: 10 },
    name: { fontFamily: fonts.display, fontSize: 27, fontWeight: '700', letterSpacing: -0.4, color: c.text },
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
    open: { color: c.green, fontWeight: '600' },
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
    hero: { width: '100%', height: 190, borderRadius: 16, marginBottom: 4 },
    sectionTitle: { fontFamily: fonts.display, fontSize: 19, fontWeight: '700', color: c.text, letterSpacing: -0.2 },
    updatedAt: { fontSize: 12, color: c.muted, marginTop: 6 },
    menuButton: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 10,
      paddingVertical: 14,
      paddingHorizontal: 16,
      borderRadius: 14,
      borderWidth: 1.5,
      borderColor: c.primary,
      backgroundColor: c.primarySoft,
    },
    menuButtonPressed: { opacity: 0.7 },
    menuButtonText: { flex: 1, fontSize: 16, fontWeight: '700', color: c.primary },
    menuCount: { fontSize: 13, color: c.muted },
    reviewSub: { fontFamily: fonts.display, fontSize: 15, fontWeight: '700', color: c.ink2, marginTop: 8, marginBottom: 2 },
    review: { flexDirection: 'row', gap: 10, paddingVertical: 8 },
    reviewAvatar: { width: 36, height: 36, borderRadius: 18 },
    reviewAvatarFallback: { backgroundColor: c.secondarySoft, alignItems: 'center', justifyContent: 'center' },
    reviewInitial: { color: c.secondary, fontWeight: '700', fontSize: 15 },
    reviewBody: { flex: 1, gap: 3 },
    reviewName: { fontSize: 14, color: c.text, fontWeight: '600' },
    reviewStars: { color: c.gold },
    reviewText: { fontSize: 14, color: c.ink2, lineHeight: 19 },
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
