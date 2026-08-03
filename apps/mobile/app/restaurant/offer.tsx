import { Stack, router, useLocalSearchParams } from 'expo-router';
import { useMemo, useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useCreateOffer, useOffer, useUpdateOffer, useVenues } from '@/api/hooks/useOffers';
import { type DiscountType, type Offer, OFFER_DURATIONS, OFFER_LIMITS } from '@/api/offers';
import { Button } from '@/components/button';
import { OfferCard } from '@/components/offer/offer-card';
import { ScreenHeader } from '@/components/screen-header';
import { TextField } from '@/components/text-field';
import { useT } from '@/i18n';
import { formErrors } from '@/lib/form-errors';
import { useFormat } from '@/lib/use-format';
import { useSettingsStore } from '@/stores/settings';
import { fonts, type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * Create or edit an offer (T-042). One screen for both — the fields are
 * identical, and a separate "edit" route would be the same form with a
 * different fetch.
 *
 * The screen leads with a LIVE PREVIEW of the offer card the diner will see,
 * rendered by the same component the list uses. An operator is committing to a
 * discount they will honour at the till; showing them the exact card as they
 * type is the difference between "20" and understanding they just promised 20%
 * off every bill for three months.
 *
 * The validity window is chosen as a DURATION, not two dates. That is a
 * deliberate product call: a date picker would mean a native module (and a dev
 * client rebuild), and "run this for 30 days" is how a restaurant actually
 * thinks about a promotion. 06 §2.2 caps a run at 90 days, so every option on
 * offer is a legal one — the cap cannot be reached by accident.
 */
export default function OfferFormScreen() {
  const c = useColors();
  const t = useT();
  const fmt = useFormat();
  const currency = useSettingsStore((s) => s.currency);
  const styles = useMemo(() => makeStyles(c), [c]);

  const params = useLocalSearchParams<{ id?: string; placeId?: string }>();
  const offerId = params.id ?? null;
  const editing = offerId !== null;

  const { data: venues } = useVenues();
  const { data: existing, isLoading } = useOffer(offerId);
  const create = useCreateOffer();
  const update = useUpdateOffer();
  const pending = create.isPending || update.isPending;
  const { fieldErrors, generalError } = formErrors(create.error ?? update.error);

  const [placeId, setPlaceId] = useState<string | null>(params.placeId ?? null);
  const [title, setTitle] = useState('');
  const [discountType, setDiscountType] = useState<DiscountType>('percent');
  const [value, setValue] = useState('');
  const [terms, setTerms] = useState('');
  const [quotaTotal, setQuotaTotal] = useState('');
  const [quotaPerDay, setQuotaPerDay] = useState('');
  /**
   * On CREATE a run length is preselected, so the preview shows a real end date
   * from the first frame — an offer with no window is not a thing this form can
   * produce, and "no end date" would contradict the 90-day cap it enforces. On
   * EDIT it starts null, meaning "the stored window is untouched", so a PATCH
   * that only renames the offer does not re-base its dates.
   */
  const [durationDays, setDurationDays] = useState<number | null>(params.id ? null : DEFAULT_DURATION_DAYS);
  /**
   * Which offer the fields currently hold. Seeding happens during render (the
   * documented React pattern for adjusting state when the data changes) rather
   * than in an effect — an effect would paint one frame of an empty form over
   * an offer that is already in hand.
   */
  const [seededId, setSeededId] = useState<string | null>(null);

  if (existing && seededId !== existing.id) {
    setSeededId(existing.id);
    setPlaceId(existing.place_id);
    setTitle(existing.title);
    setDiscountType(existing.discount_type);
    setValue(formValue(existing));
    setTerms(existing.terms ?? '');
    setQuotaTotal(existing.quota_total?.toString() ?? '');
    setQuotaPerDay(existing.quota_per_day?.toString() ?? '');
  }

  const startsAt = existing?.starts_at ?? nowIso();
  const endsAt = durationDays === null ? (existing?.ends_at ?? null) : addDaysIso(startsAt, durationDays);

  const numericValue = toDiscountValue(discountType, value);
  const preview = previewOffer({
    existing,
    placeId: placeId ?? '0',
    title: title.trim(),
    discountType,
    discountValue: numericValue,
    startsAt,
    endsAt,
    quotaTotal: toNullableInt(quotaTotal),
    quotaPerDay: toNullableInt(quotaPerDay),
  });

  const canSubmit = !!placeId && title.trim().length > 0 && numericValue > 0 && !pending;

  function submit(status: 'draft' | 'active') {
    if (!canSubmit) return;

    const body = {
      title: title.trim(),
      discount_type: discountType,
      discount_value: numericValue,
      terms: terms.trim() || null,
      quota_total: toNullableInt(quotaTotal),
      quota_per_day: toNullableInt(quotaPerDay),
    };

    if (editing) {
      update.mutate(
        {
          id: offerId,
          ...body,
          // NO `status` on an edit. Pausing, resuming and publishing live on the
          // list screen, where they are deliberate one-tap acts. Sending one
          // here would mean an operator who paused an offer, fixed a typo in its
          // terms and pressed Save had silently put it back in front of diners.
          // Only when the operator actually re-picked a run length — otherwise
          // a rename would silently re-base the window from today.
          ...(durationDays === null ? {} : { starts_at: startsAt, ends_at: endsAt }),
        },
        { onSuccess: () => router.back() },
      );

      return;
    }

    create.mutate(
      { place_id: placeId as string, ...body, status, starts_at: startsAt, ends_at: endsAt },
      { onSuccess: () => router.back() },
    );
  }

  if (editing && isLoading) {
    return (
      <SafeAreaView style={styles.safe} edges={['top']}>
        <Stack.Screen options={{ headerShown: false }} />
        <ScreenHeader title={t('offers.form.editTitle')} />
        <ActivityIndicator color={c.primary} style={styles.loading} accessibilityLabel={t('common.loading')} />
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader title={t(editing ? 'offers.form.editTitle' : 'offers.form.newTitle')} divided />

      <KeyboardAvoidingView style={styles.fill} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
          <View style={styles.previewWrap}>
            <Text style={styles.previewLabel}>{t('offers.form.preview')}</Text>
            <OfferCard offer={preview} placeholder />
          </View>

          {venues && venues.length > 1 && !editing ? (
            <Section title={t('offers.form.venue')} styles={styles}>
              <View style={styles.chipRow}>
                {venues.map((v) => (
                  <Chip
                    key={v.id}
                    label={v.name}
                    selected={placeId === v.id}
                    onPress={() => setPlaceId(v.id)}
                    styles={styles}
                  />
                ))}
              </View>
            </Section>
          ) : null}

          <Section title={t('offers.form.deal')} styles={styles}>
            <View style={styles.chipRow}>
              {(['percent', 'fixed_amount', 'free_item'] as const).map((type_) => (
                <Chip
                  key={type_}
                  label={t(DISCOUNT_LABEL[type_])}
                  selected={discountType === type_}
                  onPress={() => {
                    setDiscountType(type_);
                    // The number means something different in each unit; keeping
                    // "20" across a switch would silently turn 20% into €0.20.
                    setValue('');
                  }}
                  styles={styles}
                />
              ))}
            </View>

            <TextField
              label={t(VALUE_LABEL[discountType])}
              value={value}
              onChangeText={setValue}
              keyboardType="decimal-pad"
              placeholder={t(VALUE_PLACEHOLDER[discountType])}
              error={fieldErrors.discount_value}
              testID="offer-value"
            />
            <Text style={styles.hint}>{t(VALUE_HINT[discountType], { currency, max: OFFER_LIMITS.percentMax })}</Text>

            <TextField
              label={t('offers.form.name')}
              value={title}
              onChangeText={setTitle}
              autoCapitalize="sentences"
              placeholder={t('offers.form.namePlaceholder')}
              error={fieldErrors.title}
              testID="offer-title"
            />
          </Section>

          <Section title={t('offers.form.runsFor')} styles={styles}>
            <View style={styles.chipRow}>
              {OFFER_DURATIONS.map((days) => (
                <Chip
                  key={days}
                  label={t('offers.form.days', { count: days })}
                  selected={durationDays === days}
                  onPress={() => setDurationDays(days)}
                  styles={styles}
                />
              ))}
            </View>
            <Text style={styles.hint}>
              {endsAt
                ? t('offers.form.until', { date: fmt.date(endsAt) })
                : t('offers.form.pickDuration')}
            </Text>
            {fieldErrors.ends_at ? <Text style={styles.error}>{fieldErrors.ends_at}</Text> : null}
          </Section>

          <Section title={t('offers.form.limits')} styles={styles}>
            <TextField
              label={t('offers.form.quotaTotal')}
              value={quotaTotal}
              onChangeText={setQuotaTotal}
              keyboardType="number-pad"
              placeholder={t('offers.form.unlimited')}
              error={fieldErrors.quota_total}
              testID="offer-quota-total"
            />
            <TextField
              label={t('offers.form.quotaPerDay')}
              value={quotaPerDay}
              onChangeText={setQuotaPerDay}
              keyboardType="number-pad"
              placeholder={t('offers.form.unlimited')}
              error={fieldErrors.quota_per_day}
              testID="offer-quota-per-day"
            />
            <Text style={styles.hint}>{t('offers.form.quotaHint')}</Text>
          </Section>

          <Section title={t('offers.form.terms')} styles={styles}>
            <TextField
              label={t('offers.form.termsLabel')}
              value={terms}
              onChangeText={setTerms}
              multiline
              numberOfLines={4}
              autoCapitalize="sentences"
              placeholder={t('offers.form.termsPlaceholder')}
              style={styles.textarea}
              error={fieldErrors.terms}
              testID="offer-terms"
            />
            <Text style={styles.hint}>{t('offers.form.termsHint')}</Text>
          </Section>

          {generalError ? <Text style={styles.error}>{t(generalError)}</Text> : null}

          <View style={styles.footer}>
            <Button
              title={t(editing ? 'offers.form.save' : 'offers.form.publish')}
              onPress={() => submit('active')}
              disabled={!canSubmit}
              loading={pending}
              testID="offer-submit"
            />
            {!editing ? (
              <Button
                title={t('offers.form.saveDraft')}
                variant="secondary"
                onPress={() => submit('draft')}
                disabled={!canSubmit}
              />
            ) : null}
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function Section({
  title,
  children,
  styles,
}: {
  title: string;
  children: React.ReactNode;
  styles: ReturnType<typeof makeStyles>;
}) {
  return (
    <View style={styles.section}>
      <Text style={styles.sectionTitle}>{title}</Text>
      {children}
    </View>
  );
}

function Chip({
  label,
  selected,
  onPress,
  styles,
}: {
  label: string;
  selected: boolean;
  onPress: () => void;
  styles: ReturnType<typeof makeStyles>;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected }}
      accessibilityLabel={label}
      onPress={onPress}
      style={({ pressed }) => [styles.chip, selected && styles.chipOn, pressed && styles.chipPressed]}
    >
      <Text style={[styles.chipText, selected && styles.chipTextOn]} numberOfLines={1}>
        {label}
      </Text>
    </Pressable>
  );
}

/** Preselected run length on create — the middle option, a month-long promo. */
const DEFAULT_DURATION_DAYS = 30;

const DISCOUNT_LABEL = {
  percent: 'offers.type.percent',
  fixed_amount: 'offers.type.fixed',
  free_item: 'offers.type.free',
} as const;

const VALUE_LABEL = {
  percent: 'offers.form.percentLabel',
  fixed_amount: 'offers.form.amountLabel',
  free_item: 'offers.form.itemsLabel',
} as const;

const VALUE_PLACEHOLDER = {
  percent: 'offers.form.percentPlaceholder',
  fixed_amount: 'offers.form.amountPlaceholder',
  free_item: 'offers.form.itemsPlaceholder',
} as const;

const VALUE_HINT = {
  percent: 'offers.form.percentHint',
  fixed_amount: 'offers.form.amountHint',
  free_item: 'offers.form.itemsHint',
} as const;

/**
 * The typed value → the integer the API stores.
 *
 * A fixed amount is entered in MAJOR units ("3.50", because nobody types 350
 * meaning €3.50) and stored in minor ones. Rounding here rather than truncating
 * means "3.999" becomes 400, not 399 — a cent of drift in the restaurant's
 * favour beats a cent that came from the parser losing a digit.
 *
 * Exported for the unit test: this conversion is the one place a UI bug becomes
 * a money bug.
 */
export function toDiscountValue(type_: DiscountType, raw: string): number {
  const n = Number.parseFloat(raw.replace(',', '.'));
  if (!Number.isFinite(n) || n <= 0) return 0;

  return type_ === 'fixed_amount' ? Math.round(n * 100) : Math.floor(n);
}

/** An emptied numeric field means "no cap", which the API spells `null`. */
export function toNullableInt(raw: string): number | null {
  const n = Number.parseInt(raw, 10);
  return Number.isFinite(n) && n > 0 ? n : null;
}

/** The stored integer → what belongs in the input, inverting the above. */
function formValue(offer: Offer): string {
  return offer.discount_type === 'fixed_amount'
    ? (offer.discount_value / 100).toFixed(2)
    : offer.discount_value.toString();
}

function nowIso(): string {
  return new Date().toISOString();
}

function addDaysIso(from: string, days: number): string {
  const d = new Date(from);
  d.setDate(d.getDate() + days);
  return d.toISOString();
}

/**
 * The in-progress offer, shaped exactly like one from the API so the preview
 * can go through the real card. Counters come from the stored row when editing
 * — an operator raising a quota needs to see it against redemptions already
 * taken, not against zero.
 */
function previewOffer(input: {
  existing: Offer | undefined;
  placeId: string;
  title: string;
  discountType: DiscountType;
  discountValue: number;
  startsAt: string;
  endsAt: string | null;
  quotaTotal: number | null;
  quotaPerDay: number | null;
}): Offer {
  const redeemed = input.existing?.redemptions_count ?? 0;

  return {
    id: input.existing?.id ?? 'preview',
    place_id: input.placeId,
    title: input.title,
    description: input.existing?.description ?? null,
    discount_type: input.discountType,
    discount_value: input.discountValue,
    terms: input.existing?.terms ?? null,
    starts_at: input.startsAt,
    ends_at: input.endsAt,
    quota_total: input.quotaTotal,
    quota_per_user: input.existing?.quota_per_user ?? 1,
    quota_per_day: input.quotaPerDay,
    redemptions_count: redeemed,
    remaining_quota: input.quotaTotal === null ? null : Math.max(0, input.quotaTotal - redeemed),
    // The stored state when editing — a paused offer must not preview as live,
    // or the card promises a diner something the offer is not currently doing.
    status: input.existing?.status ?? 'active',
    is_redeemable: (input.existing?.status ?? 'active') === 'active',
    created_at: null,
    updated_at: null,
  };
}

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    fill: { flex: 1 },
    loading: { paddingVertical: space.xxl },
    scroll: { padding: space.md, gap: space.lg, paddingBottom: space.xxl },

    previewWrap: { gap: space.xs },
    previewLabel: { ...type.caption, color: c.muted, textTransform: 'uppercase', letterSpacing: 0.6 },

    section: {
      gap: space.sm,
      backgroundColor: c.surface,
      borderRadius: radius.lg,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      padding: space.md,
    },
    sectionTitle: { ...type.bodyLg, fontFamily: fonts.display, color: c.text },
    hint: { ...type.bodySm, color: c.muted },
    error: { ...type.bodySm, color: c.danger },
    textarea: { minHeight: 96, textAlignVertical: 'top' },

    chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: space.xs },
    chip: {
      paddingHorizontal: space.sm,
      paddingVertical: space.xs,
      borderRadius: radius.pill,
      borderWidth: 1,
      borderColor: c.line2,
      backgroundColor: c.surface2,
    },
    chipOn: { backgroundColor: c.primarySoft, borderColor: c.primary },
    chipPressed: { opacity: 0.7 },
    chipText: { ...type.bodySm, color: c.ink2 },
    chipTextOn: { color: c.primary, fontWeight: '700' },

    footer: { gap: space.sm },
  });
