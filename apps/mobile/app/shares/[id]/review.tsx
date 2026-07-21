import { Ionicons } from '@expo/vector-icons';
import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useDiscardShare } from '@/api/hooks/useDiscardShare';
import { useShareStatus } from '@/api/hooks/useShareStatus';
import { useUpdateShare } from '@/api/hooks/useUpdateShare';
import {
  type ExtractionPlace,
  hasEditableExtraction,
  type ShareDetail,
  type ShareUpdatePayload,
} from '@/api/shares';
import { ValidationError } from '@/api/types';
import { Button } from '@/components/button';
import { CandidatePicker } from '@/components/share/review/candidate-picker';
import { ChipSelect, type ChipOption } from '@/components/share/review/chip-select';
import { ConfidenceField } from '@/components/share/review/confidence-field';
import { DishEditor } from '@/components/share/review/dish-editor';
import { EvidencePanel } from '@/components/share/review/evidence-panel';
import { PinAdjuster } from '@/components/share/review/pin-adjuster';
import { PriceSelect } from '@/components/share/review/price-select';
import { type MessageKey, useT } from '@/i18n';
import { safeBack } from '@/lib/nav';
import { fonts, type Palette, useColors } from '@/theme/colors';

// App default center (matches the map screen) — the pin's starting point when the
// extraction carries no coordinates (a geocode_failed review). Publishing without
// touching it re-geocodes from the corrected address instead.
const FALLBACK = { lat: -34.9, lng: -56.16 };

const CATEGORY_VALUES = [
  'restaurant', 'cafe', 'bar', 'bakery', 'street_food', 'food_truck', 'dessert', 'market', 'other',
] as const;

// The fixed vibe / dietary enums from the extraction schema. Displayed title-cased
// (they're canonical English labels; discovery-side translation is server-driven).
const VIBE_VALUES = [
  'cozy', 'romantic', 'lively', 'quiet', 'casual', 'upscale', 'trendy', 'minimalist', 'rustic',
  'family friendly', 'outdoor seating', 'rooftop', 'great view', 'good for groups', 'date night',
  'counter seating', 'pet friendly', 'live music', 'brunch spot', 'late night', 'quick eats',
  'hidden gem', 'spacious',
];
const DIETARY_VALUES = [
  'vegan', 'vegan options', 'vegetarian', 'vegetarian options', 'gluten-free', 'dairy-free',
  'halal', 'kosher', 'organic', 'plant-based',
];

const titleCase = (v: string) =>
  v.replace(/[-_]/g, ' ').replace(/\b\w/g, (ch) => ch.toUpperCase());

const toChips = (values: readonly string[], label: (v: string) => string): ChipOption[] =>
  values.map((v) => ({ value: v, label: label(v) }));

// Vibe/dietary labels aren't localized (canonical English enums), so the chip
// options are fully static — build them once at module load, not per render.
const VIBE_OPTIONS = toChips(VIBE_VALUES, titleCase);
const DIETARY_OPTIONS = toChips(DIETARY_VALUES, titleCase);

/** Deep clone the extraction place so edits don't mutate the cached query data. */
const clonePlace = (p: ExtractionPlace): ExtractionPlace => JSON.parse(JSON.stringify(p));

/**
 * Loader: fetch the share, then hand a guaranteed review-with-extraction to the
 * form. A share that isn't editable (published / failed / a fetch failure with
 * no extraction) has nothing to correct, so it bounces to the status screen.
 */
export default function ReviewScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const shareId = id ?? '';
  const c = useColors();
  const styles = useMemo(() => makeStyles(c), [c]);

  const { data: share, isLoading } = useShareStatus(shareId || null);
  const notEditable = !!share && (share.status !== 'review' || !hasEditableExtraction(share));

  useEffect(() => {
    if (notEditable) router.replace({ pathname: '/shares/[id]/status', params: { id: shareId } });
  }, [notEditable, shareId]);

  if (isLoading || !share || notEditable) {
    return (
      <SafeAreaView style={styles.safe} edges={['top']}>
        <Stack.Screen options={{ headerShown: false }} />
        <View style={styles.center}>
          <ActivityIndicator color={c.primary} />
        </View>
      </SafeAreaView>
    );
  }
  return <ReviewForm share={share} shareId={shareId} />;
}

function ReviewForm({ share, shareId }: { share: ShareDetail; shareId: string }) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const update = useUpdateShare(shareId);
  const discard = useDiscardShare(shareId);

  const first = share.analysis!.extraction!.places[0];
  // Editable copy of the (single) extracted place — lazily cloned so edits never
  // mutate the cached query data.
  const [place, setPlace] = useState<ExtractionPlace>(() => clonePlace(first));
  const [initialPin] = useState(() => (first.geo ? { lat: first.geo.lat, lng: first.geo.lng } : FALLBACK));
  const [pin, setPin] = useState(initialPin);
  const pinTouched = useRef(false);
  const [candidateId, setCandidateId] = useState<number | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [saveError, setSaveError] = useState<string | null>(null);

  const perField = share.analysis?.extraction?.confidence?.per_field ?? {};
  const conf = (leaf: string): number | null => perField[`places[0].${leaf}`] ?? null;
  const candidates = share.pending_places?.[0]?.candidates ?? [];

  const patch = <K extends keyof ExtractionPlace>(key: K, value: ExtractionPlace[K]) =>
    setPlace((p) => ({ ...p, [key]: value }));
  const patchAddress = (key: keyof ExtractionPlace['address'], value: string) =>
    setPlace((p) => ({ ...p, address: { ...p.address, [key]: value || null } }));

  // Mark the pin "touched" only on a real drag. react-native-maps fires an initial
  // settle whose center can differ from the seed by a few metres (tile snap), so
  // compare against the ORIGINAL seed with a ~11 m threshold — otherwise that
  // spurious settle would send the fallback coordinate and pin the place at the
  // map's default centre. An untouched pin lets the backend re-geocode the address.
  const onPinChange = (lat: number, lng: number) => {
    if (Math.abs(lat - initialPin.lat) > 1e-4 || Math.abs(lng - initialPin.lng) > 1e-4) {
      pinTouched.current = true;
    }
    setPin({ lat, lng });
  };

  // The API validates the merged extraction with opis/json-schema, which reports
  // failures as JSON-Pointer keys (`/places/0/address/street`) — copied verbatim
  // into the 422 interceptor's `fields`. Look up the exact pointer for places[0]
  // (review is single-place); a suffix/dot match would never hit the slash keys
  // and could mis-attach a `/places/0/dishes/0/name` error to the place name.
  const errorFor = (leaf: string): string | undefined =>
    fieldErrors[`/places/0/${leaf.replace(/\./g, '/')}`];

  const onConfirm = () => {
    setSaveError(null);
    setFieldErrors({});

    const body: ShareUpdatePayload = {
      extraction: {
        places: [
          {
            name: place.name,
            handle: place.handle,
            category: place.category,
            cuisines: place.cuisines,
            address: place.address,
            price_range: place.price_range,
            dishes: place.dishes,
            vibe_tags: place.vibe_tags,
            dietary_tags: place.dietary_tags,
          },
        ],
      },
      action: 'publish',
    };
    // Attach to an existing place, else drop a manual pin ONLY if the user moved
    // it — an untouched pin lets the backend re-geocode the corrected address.
    if (candidateId != null) body.place_candidate = { place_id: candidateId };
    else if (pinTouched.current) body.place_candidate = { lat: pin.lat, lng: pin.lng };

    update.mutate(body, {
      onSuccess: () => router.replace({ pathname: '/shares/[id]/status', params: { id: shareId } }),
      onError: (err) => {
        if (err instanceof ValidationError) {
          setFieldErrors(err.fields);
          setSaveError(err.message);
        } else {
          setSaveError(t('review.saveError'));
        }
      },
    });
  };

  const onDiscard = () => {
    Alert.alert(t('review.discardTitle'), t('review.discardBody'), [
      { text: t('review.discardCancel'), style: 'cancel' },
      {
        text: t('review.discardConfirm'),
        style: 'destructive',
        onPress: () =>
          discard.mutate(undefined, {
            onSuccess: () => router.replace('/(main)/share'),
            onError: () => setSaveError(t('review.saveError')),
          }),
      },
    ]);
  };

  // Category labels ARE localized, so memoize on the translator (stable per locale)
  // rather than rebuilding all 9 chips on every keystroke.
  const categoryOptions = useMemo(
    () => toChips(CATEGORY_VALUES, (v) => t(`review.category.${v}` as MessageKey)),
    [t],
  );
  const busy = update.isPending || discard.isPending;

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.header}>
        <Pressable accessibilityRole="button" accessibilityLabel={t('place.back')} onPress={safeBack} hitSlop={12}>
          <Ionicons name="chevron-back" size={26} color={c.text} />
        </Pressable>
        <Text style={styles.headerTitle}>{t('review.title')}</Text>
        <View style={styles.headerSpacer} />
      </View>

      <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
        <Text style={styles.subtitle}>{t('review.subtitle')}</Text>

        {/* PLACE */}
        <Section title={t('review.section.place')} styles={styles}>
          <ConfidenceField
            label={t('review.field.name')}
            value={place.name ?? ''}
            onChangeText={(v) => patch('name', v || null)}
            placeholder={t('review.field.namePlaceholder')}
            autoCapitalize="words"
            confidence={conf('name')}
            error={errorFor('name')}
          />
          <ChipSelect
            label={t('review.field.category')}
            options={categoryOptions}
            selected={place.category ? [place.category] : []}
            onToggle={(v) => patch('category', (place.category === v ? null : v) as ExtractionPlace['category'])}
          />
          <ConfidenceField
            label={t('review.field.cuisines')}
            value={place.cuisines.join(', ')}
            onChangeText={(v) =>
              patch('cuisines', v.split(',').map((s) => s.trim().toLowerCase()).filter(Boolean))
            }
            placeholder={t('review.field.cuisinesPlaceholder')}
            confidence={conf('cuisines')}
          />
          <PriceSelect
            label={t('review.field.price')}
            value={place.price_range}
            onChange={(v) => patch('price_range', v)}
          />
          <ConfidenceField
            label={t('review.field.handle')}
            value={place.handle ?? ''}
            onChangeText={(v) => patch('handle', v.replace(/^@/, '').trim() || null)}
            placeholder="@handle"
            autoCapitalize="none"
            confidence={conf('handle')}
          />
        </Section>

        {/* LOCATION */}
        <Section title={t('review.section.location')} styles={styles}>
          <CandidatePicker candidates={candidates} selectedId={candidateId} onSelect={setCandidateId} />
          {candidateId == null ? (
            <>
              <PinAdjuster lat={pin.lat} lng={pin.lng} onChange={onPinChange} />
              <ConfidenceField
                label={t('review.field.street')}
                value={place.address.street ?? ''}
                onChangeText={(v) => patchAddress('street', v)}
                autoCapitalize="words"
                confidence={conf('address.street')}
                error={errorFor('address.street')}
              />
              <View style={styles.row}>
                <View style={styles.rowItem}>
                  <ConfidenceField
                    label={t('review.field.city')}
                    value={place.address.city ?? ''}
                    onChangeText={(v) => patchAddress('city', v)}
                    autoCapitalize="words"
                    confidence={conf('address.city')}
                  />
                </View>
                <View style={styles.rowItem}>
                  <ConfidenceField
                    label={t('review.field.region')}
                    value={place.address.region ?? ''}
                    onChangeText={(v) => patchAddress('region', v)}
                    autoCapitalize="words"
                    confidence={conf('address.region')}
                  />
                </View>
              </View>
              <View style={styles.row}>
                <View style={styles.rowItem}>
                  <ConfidenceField
                    label={t('review.field.postalCode')}
                    value={place.address.postal_code ?? ''}
                    onChangeText={(v) => patchAddress('postal_code', v)}
                    confidence={conf('address.postal_code')}
                  />
                </View>
                <View style={styles.rowItem}>
                  <ConfidenceField
                    label={t('review.field.country')}
                    value={place.address.country ?? ''}
                    onChangeText={(v) => patchAddress('country', v)}
                    autoCapitalize="characters"
                    confidence={conf('address.country')}
                  />
                </View>
              </View>
            </>
          ) : null}
        </Section>

        {/* DETAILS */}
        <Section title={t('review.section.details')} styles={styles}>
          <DishEditor
            label={t('review.field.dishes')}
            dishes={place.dishes}
            onChange={(d) => patch('dishes', d)}
          />
          <ChipSelect
            label={t('review.field.vibe')}
            options={VIBE_OPTIONS}
            selected={place.vibe_tags}
            onToggle={(v) => patch('vibe_tags', toggle(place.vibe_tags, v) as ExtractionPlace['vibe_tags'])}
          />
          <ChipSelect
            label={t('review.field.dietary')}
            options={DIETARY_OPTIONS}
            selected={place.dietary_tags}
            onToggle={(v) => patch('dietary_tags', toggle(place.dietary_tags, v) as ExtractionPlace['dietary_tags'])}
          />
        </Section>

        {/* EVIDENCE */}
        <Section title={t('review.section.evidence')} styles={styles}>
          {share.source_post.url ? (
            <Text style={styles.sourceUrl} numberOfLines={1}>
              {share.source_post.url.replace(/^https?:\/\//, '')}
            </Text>
          ) : null}
          <EvidencePanel evidence={share.analysis?.extraction?.evidence} />
        </Section>

        {saveError ? <Text style={styles.saveError}>{saveError}</Text> : null}

        <Button
          title={update.isPending ? t('review.publishing') : t('review.confirm')}
          onPress={onConfirm}
          loading={update.isPending}
          disabled={busy}
        />
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('review.discard')}
          onPress={onDiscard}
          disabled={busy}
          hitSlop={8}
          style={styles.discardBtn}
        >
          <Text style={styles.discardText}>{t('review.discard')}</Text>
        </Pressable>
      </ScrollView>
    </SafeAreaView>
  );
}

const toggle = (list: string[], value: string): string[] =>
  list.includes(value) ? list.filter((v) => v !== value) : [...list, value];

function Section({ title, styles, children }: { title: string; styles: Styles; children: React.ReactNode }) {
  return (
    <View style={styles.section}>
      <Text style={styles.sectionTitle}>{title}</Text>
      <View style={styles.sectionBody}>{children}</View>
    </View>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
    header: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: 12,
      paddingHorizontal: 16,
      paddingVertical: 10,
      borderBottomWidth: StyleSheet.hairlineWidth,
      borderBottomColor: c.border,
    },
    headerTitle: { flex: 1, fontFamily: fonts.display, fontSize: 20, fontWeight: '700', color: c.text },
    headerSpacer: { width: 26 },
    scroll: { padding: 20, gap: 20, paddingBottom: 48 },
    subtitle: { fontSize: 15, color: c.muted, lineHeight: 21, marginTop: -4 },
    section: { gap: 12 },
    sectionTitle: {
      fontSize: 12,
      fontWeight: '800',
      letterSpacing: 0.6,
      textTransform: 'uppercase',
      color: c.primary,
    },
    sectionBody: { gap: 16 },
    row: { flexDirection: 'row', gap: 12 },
    rowItem: { flex: 1 },
    sourceUrl: { fontSize: 13, color: c.secondary, fontWeight: '600' },
    saveError: { fontSize: 14, color: c.danger, textAlign: 'center' },
    discardBtn: { alignItems: 'center', paddingVertical: 8 },
    discardText: { fontSize: 15, fontWeight: '700', color: c.danger },
  });
