import { Ionicons } from '@expo/vector-icons';
import { memo, useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { type Offer, type OfferState, offerState } from '@/api/offers';
import { useT } from '@/i18n';
import type { MessageKey } from '@/i18n';
import { useFormat } from '@/lib/use-format';
import { useSettingsStore } from '@/stores/settings';
import { fonts, type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/** The `t()` returned by {@link useT} — passed to the pure label helpers below. */
type Translate = (key: MessageKey, params?: Record<string, string | number>) => string;

type Props = {
  offer: Offer;
  /** Venue name, shown when the list spans more than one restaurant. */
  venueName?: string | null;
  onPress?: () => void;
  /** Trailing action row (pause / edit / archive). Omitted in the form preview. */
  actions?: React.ReactNode;
  /**
   * Render the card as an UNFILLED draft: the headline and title fall back to
   * muted placeholders instead of showing the zero they currently hold.
   *
   * Only the form's live preview passes this. Without it an empty form reads
   * "0%" — which is not "nothing entered yet", it is a promise of a nought
   * percent discount, and it is the first thing an operator sees.
   */
  placeholder?: boolean;
};

/**
 * One offer, as a market price-card (T-042).
 *
 * The discount is the headline — set in the display serif at hero size, because
 * "30%" is the entire product and the title is the footnote. A coloured stub
 * runs down the left edge: it is the only element that encodes state
 * pre-attentively, so an operator scanning ten offers sees which are live
 * without reading a single badge.
 *
 * The SAME component renders the list row and the form's live preview. That is
 * deliberate — a preview built from a second, simpler layout is a preview that
 * can lie about what publishing will produce.
 */
function OfferCardBase({ offer, venueName, onPress, actions, placeholder }: Props) {
  const c = useColors();
  const t = useT();
  const fmt = useFormat();
  const currency = useSettingsStore((s) => s.currency);
  const styles = useMemo(() => makeStyles(c), [c]);

  const look = STATE[offerState(offer)];
  const accent = look.accent(c);
  const unfilled = placeholder === true && offer.discount_value <= 0;
  const headline = unfilled ? '—' : discountHeadline(offer, currency, t);

  const window = [fmt.date(offer.starts_at), offer.ends_at ? fmt.date(offer.ends_at) : t('offers.openEnded')]
    .filter(Boolean)
    .join(' → ');

  const used = offer.redemptions_count;
  const cap = offer.quota_total;
  // Clamped: a counter that overshot a lowered cap must read "full", not
  // overflow the meter.
  const fill = cap ? Math.min(100, Math.round((used / cap) * 100)) : 0;

  const body = (
    <>
      <View style={[styles.stub, { backgroundColor: accent }]} />
      <View style={styles.body}>
        {venueName ? (
          <Text style={styles.venue} numberOfLines={1}>
            {venueName}
          </Text>
        ) : null}

        <View style={styles.headRow}>
          <Text
            style={[styles.headline, unfilled && styles.headlineEmpty]}
            numberOfLines={1}
            accessibilityLabel={headline}
          >
            {headline}
          </Text>
          <View style={[styles.pill, { backgroundColor: look.fill(c) }]}>
            <Text style={[styles.pillText, { color: accent }]}>{t(look.label)}</Text>
          </View>
        </View>

        <Text style={[styles.title, offer.title === '' && styles.titleEmpty]} numberOfLines={2}>
          {offer.title === '' ? t('offers.form.namePlaceholder') : offer.title}
        </Text>

        <View style={styles.metaRow}>
          <Ionicons name="calendar-outline" size={13} color={c.muted} />
          <Text style={styles.meta} numberOfLines={1}>
            {window}
          </Text>
        </View>

        {cap ? (
          <View style={styles.quota}>
            <View style={styles.track}>
              <View style={[styles.fill, { width: `${fill}%` }]} />
            </View>
            <Text style={styles.quotaText}>{t('offers.redeemedOfQuota', { used, total: cap })}</Text>
          </View>
        ) : (
          <Text style={styles.quotaText}>{t('offers.redeemedCount', { count: used })}</Text>
        )}

        {actions ? <View style={styles.actions}>{actions}</View> : null}
      </View>
    </>
  );

  if (!onPress) {
    return <View style={styles.card}>{body}</View>;
  }

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${headline} — ${offer.title}`}
      onPress={onPress}
      style={({ pressed }) => [styles.card, pressed && styles.pressed]}
    >
      {body}
    </Pressable>
  );
}

export const OfferCard = memo(OfferCardBase);

/**
 * The discount, rendered in its own unit.
 *
 * `discount_value` is one integer serving three units, so this is the single
 * place that knows which is which — a raw 350 shown as "350%" instead of
 * "€3.50" is the failure this exists to prevent. Minor units are divided by 100
 * for DISPLAY only; the value on the wire stays an integer.
 *
 * Takes the translator rather than returning English: "free" is a word, and the
 * app's default language is Spanish.
 */
export function discountHeadline(
  offer: Pick<Offer, 'discount_type' | 'discount_value'>,
  currencySymbol: string,
  t: Translate,
): string {
  switch (offer.discount_type) {
    case 'percent':
      return `${offer.discount_value}%`;
    case 'fixed_amount':
      return `${currencySymbol}${(offer.discount_value / 100).toFixed(2)}`;
    case 'free_item':
      return t('offers.freeItems', { count: offer.discount_value });
  }
}

/**
 * How each state looks and reads: accent, badge fill, label.
 *
 * One entry per state rather than three parallel maps keyed by the same union —
 * those had to be edited in lockstep, and a state added to two of the three is
 * a crash (`undefined(c)`) rather than a visible gap.
 */
const STATE: Record<OfferState, { accent: (c: Palette) => string; fill: (c: Palette) => string; label: MessageKey }> = {
  live: { accent: (c) => c.green, fill: (c) => c.greenSoft, label: 'offers.state.live' },
  scheduled: { accent: (c) => c.secondary, fill: (c) => c.secondarySoft, label: 'offers.state.scheduled' },
  soldOut: { accent: (c) => c.gold, fill: (c) => c.goldSoft, label: 'offers.state.soldOut' },
  paused: { accent: (c) => c.gold, fill: (c) => c.goldSoft, label: 'offers.state.paused' },
  draft: { accent: (c) => c.muted, fill: (c) => c.surface2, label: 'offers.state.draft' },
  ended: { accent: (c) => c.ink2, fill: (c) => c.surface2, label: 'offers.state.ended' },
  archived: { accent: (c) => c.muted, fill: (c) => c.surface2, label: 'offers.state.archived' },
};

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    card: {
      flexDirection: 'row',
      backgroundColor: c.surface,
      borderRadius: radius.lg,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      // Clips the stub to the card's corners — without it the bar's square top
      // pokes past the radius.
      overflow: 'hidden',
    },
    pressed: { opacity: 0.7 },
    /** The pre-attentive state cue: a ticket stub down the left edge. */
    stub: { width: space.xxs },
    body: { flex: 1, padding: space.md, gap: space.xs },
    venue: { ...type.caption, color: c.muted, textTransform: 'uppercase', letterSpacing: 0.6 },
    headRow: { flexDirection: 'row', alignItems: 'center', gap: space.xs },
    headline: {
      ...type.hero,
      flex: 1,
      color: c.text,
      fontFamily: fonts.display,
    },
    pill: { paddingHorizontal: space.xs, paddingVertical: space.xxs, borderRadius: radius.pill },
    pillText: { ...type.caption, fontWeight: '700' },
    title: { ...type.bodyLg, color: c.text },
    titleEmpty: { color: c.placeholder },
    headlineEmpty: { color: c.placeholder },
    metaRow: { flexDirection: 'row', alignItems: 'center', gap: space.xxs },
    meta: { ...type.bodySm, flex: 1, color: c.muted },
    quota: { gap: space.xxs },
    track: { height: space.xxs, borderRadius: radius.pill, backgroundColor: c.surface2, overflow: 'hidden' },
    fill: { height: '100%', backgroundColor: c.gold },
    quotaText: { ...type.caption, color: c.muted },
    actions: {
      flexDirection: 'row',
      alignItems: 'center',
      // Wraps rather than clipping. Three labelled actions plus a spacer fit at
      // 430pt and are within a few points of the edge at 375pt (iPhone SE) — and
      // Spanish is the default language, where every one of these words is
      // longer than its English label. Wrapping makes the narrow case a second
      // line instead of a truncated one.
      flexWrap: 'wrap',
      gap: space.sm,
      marginTop: space.xxs,
      paddingTop: space.xs,
      borderTopWidth: StyleSheet.hairlineWidth,
      borderTopColor: c.border,
    },
  });
