import { Ionicons } from '@expo/vector-icons';
import type { InfluencerDashboard } from '@reelmap/contracts';
import { Stack, router } from 'expo-router';
import { useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import {
  DASHBOARD_PERIODS,
  type DashboardPeriod,
  useInfluencerDashboard,
} from '@/api/hooks/useInfluencerDashboard';
import { formatMoney } from '@/api/wallet';
import { Button } from '@/components/button';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
import { openWebUrl } from '@/lib/linking';
import { type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * "Which of my reels actually made money" (T-048, 06 §5.2).
 *
 * The wallet next door answers HOW MUCH. This answers FROM WHAT — and the
 * difference is the whole point of the screen, because a balance alone tells an
 * influencer nothing about what to post next.
 *
 * The funnel is drawn as bars that shrink stage to stage, deliberately: the
 * interesting number is never a stage on its own, it is the drop between two of
 * them ("40 people took a code, 12 walked in"). Four figures in a row would
 * make you do that subtraction in your head.
 *
 * Bars are proportional to the FIRST stage rather than each to its own maximum,
 * so a funnel that barely converts looks like one. Normalising each bar to fill
 * the width would flatter every account equally and make the chart decorative.
 */
export default function InfluencerDashboardScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);
  const [period, setPeriod] = useState<DashboardPeriod>('30d');
  const query = useInfluencerDashboard(period);

  // 403 is not a failure — it means this account never claimed an identity, so
  // it gets an explanation and a way forward rather than "try again".
  const forbidden = query.isError && (query.error as { response?: { status?: number } })?.response?.status === 403;

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader title={t('earnings.title')} divided />

      <ScrollView contentContainerStyle={styles.scroll}>
        {forbidden ? (
          <View style={styles.notice}>
            <Ionicons name="ribbon-outline" size={32} color={c.muted} />
            <Text style={styles.noticeTitle}>{t('earnings.forbidden.title')}</Text>
            <Text style={styles.noticeBody}>{t('earnings.forbidden.body')}</Text>
          </View>
        ) : (
          <>
            <PeriodSwitch value={period} onChange={setPeriod} styles={styles} c={c} t={t} />

            {query.isLoading ? (
              <ActivityIndicator color={c.primary} style={styles.loading} accessibilityLabel={t('common.loading')} />
            ) : query.isError || !query.data ? (
              <View style={styles.notice}>
                <Ionicons name="cloud-offline-outline" size={32} color={c.muted} />
                <Text style={styles.noticeBody}>{t('common.error.general')}</Text>
                <Button title={t('common.tryAgain')} variant="secondary" size="sm" onPress={() => void query.refetch()} />
              </View>
            ) : (
              <Dashboard data={query.data} styles={styles} c={c} t={t} />
            )}
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

function PeriodSwitch({
  value,
  onChange,
  styles,
  c,
  t,
}: {
  value: DashboardPeriod;
  onChange: (p: DashboardPeriod) => void;
  styles: Styles;
  c: Palette;
  t: ReturnType<typeof useT>;
}) {
  return (
    <View style={styles.segment}>
      {DASHBOARD_PERIODS.map((p) => {
        const active = p === value;
        const label = t(`earnings.period.${p}` as 'earnings.period.30d');

        return (
          <Pressable
            key={p}
            accessibilityRole="radio"
            accessibilityState={{ selected: active }}
            accessibilityLabel={label}
            onPress={() => onChange(p)}
            style={[styles.segmentItem, active && styles.segmentItemActive]}
            testID={`earnings-period-${p}`}
          >
            <Text style={[styles.segmentLabel, active && styles.segmentLabelActive]}>{label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

function Dashboard({
  data,
  styles,
  c,
  t,
}: {
  data: InfluencerDashboard;
  styles: Styles;
  c: Palette;
  t: ReturnType<typeof useT>;
}) {
  const { funnel } = data;
  // Nothing has happened yet — say so plainly instead of drawing four empty
  // bars, which reads as a broken chart rather than an empty history.
  if (funnel.issued === 0 && funnel.redeemed === 0 && funnel.earnings.amount === 0) {
    return (
      <View style={styles.notice} testID="earnings-empty">
        <Ionicons name="sparkles-outline" size={32} color={c.muted} />
        <Text style={styles.noticeTitle}>{t('earnings.empty.title')}</Text>
        <Text style={styles.noticeBody}>{t('earnings.empty.body')}</Text>
      </View>
    );
  }

  // Only `issued` and `redeemed` belong in the bars. `shares` counts POSTS, not
  // codes, and it is current reach rather than a period figure — charting it as
  // the first stage made one post producing two codes render as a bar that
  // GREW, which reads as a funnel that gains people. It is context, so it is
  // set above the bars as a line, not a stage.
  const widest = Math.max(funnel.issued, funnel.redeemed, 1);

  return (
    <>
      <View style={styles.funnel} testID="earnings-funnel">
        <Text style={styles.reach} testID="earnings-reach">
          {t('earnings.reach', { count: funnel.shares })}
        </Text>

        <FunnelBar
          value={funnel.issued}
          of={widest}
          label={t('earnings.funnel.issued', { count: funnel.issued })}
          styles={styles}
        />
        <FunnelBar
          value={funnel.redeemed}
          of={widest}
          label={t('earnings.funnel.redeemed', { count: funnel.redeemed })}
          emphasis
          styles={styles}
        />

        <View style={styles.earnedRow}>
          <Text style={styles.earned} testID="earnings-total">
            {formatMoney(funnel.earnings)}
          </Text>
          <Text style={styles.earnedLabel}>{t('earnings.funnel.earned')}</Text>
        </View>
      </View>

      {/* The task's gotcha, said to the user rather than only in the payload:
          `views: null` must never be read as "nobody looked". */}
      {funnel.views === null ? (
        <Text style={styles.footnote} testID="earnings-views-untracked">
          {t('earnings.views.untracked')}
        </Text>
      ) : null}

      {data.top_places.length > 0 ? (
        <Section title={t('earnings.topPlaces')} styles={styles}>
          {data.top_places.map((row) => (
            <Pressable
              key={row.place.id}
              accessibilityRole="button"
              accessibilityLabel={row.place.name}
              onPress={() => router.push({ pathname: '/place/[slug]', params: { slug: row.place.slug } })}
              style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
              testID={`earnings-place-${row.place.id}`}
            >
              <View style={styles.rowText}>
                <Text style={styles.rowTitle} numberOfLines={1}>
                  {row.place.name}
                </Text>
                <Text style={styles.rowMeta}>
                  {t('earnings.place.detail', { redeemed: row.redeemed, issued: row.issued })}
                </Text>
              </View>
              <Text style={styles.rowAmount}>{formatMoney(row.earnings)}</Text>
            </Pressable>
          ))}
        </Section>
      ) : null}

      {data.by_source.length > 0 ? (
        <Section title={t('earnings.bySource')} styles={styles}>
          {data.by_source.map((row, i) => {
            const url = row.post?.url ?? null;

            return (
              <Pressable
                key={row.share_id ?? `deleted-${i}`}
                accessibilityRole={url ? 'link' : 'text'}
                accessibilityLabel={url ?? t('earnings.post.deleted')}
                // A row whose share was deleted keeps its earnings but has
                // nowhere to go — disabled rather than hidden, because hiding
                // it would make the rows stop summing to the total above.
                disabled={!url}
                onPress={() => openWebUrl(url)}
                style={({ pressed }) => [styles.row, pressed && url && styles.rowPressed]}
                testID={`earnings-source-${row.share_id ?? 'deleted'}`}
              >
                <View style={styles.rowText}>
                  <Text style={[styles.rowTitle, !url && styles.rowTitleMuted]} numberOfLines={1}>
                    {url ?? t('earnings.post.deleted')}
                  </Text>
                  <Text style={styles.rowMeta}>
                    {t('earnings.place.detail', { redeemed: row.redeemed, issued: row.issued })}
                  </Text>
                </View>
                <Text style={styles.rowAmount}>{formatMoney(row.earnings)}</Text>
              </Pressable>
            );
          })}
        </Section>
      ) : null}
    </>
  );
}

/** One stage of the funnel: a number, its label, and a bar sized against the widest stage. */
function FunnelBar({
  value,
  of,
  label,
  emphasis = false,
  styles,
}: {
  value: number;
  of: number;
  label: string;
  emphasis?: boolean;
  styles: Styles;
}) {
  // A floor of 2% so a real-but-tiny stage still draws something — a zero-width
  // bar next to a non-zero number looks like a rendering bug.
  const pct = Math.max(2, Math.round((value / of) * 100));

  return (
    <View style={styles.stage}>
      <View style={styles.stageHead}>
        <Text style={styles.stageValue}>{value}</Text>
        <Text style={styles.stageLabel}>{label}</Text>
      </View>
      <View style={styles.track}>
        <View style={[styles.bar, emphasis && styles.barEmphasis, { width: `${pct}%` }]} />
      </View>
    </View>
  );
}

function Section({ title, children, styles }: { title: string; children: React.ReactNode; styles: Styles }) {
  return (
    <View style={styles.section}>
      <Text style={styles.sectionTitle}>{title}</Text>
      <View style={styles.group}>{children}</View>
    </View>
  );
}

type Styles = ReturnType<typeof makeStyles>;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    scroll: { padding: space.md, gap: space.lg, paddingBottom: space.xl },
    loading: { paddingVertical: space.xl },

    segment: {
      flexDirection: 'row',
      backgroundColor: c.surface2,
      borderRadius: radius.pill,
      padding: space.xxs,
    },
    segmentItem: { flex: 1, alignItems: 'center', paddingVertical: space.xs, borderRadius: radius.pill },
    segmentItemActive: { backgroundColor: c.surface },
    segmentLabel: { ...type.bodySm, color: c.muted, fontWeight: '600' },
    segmentLabelActive: { color: c.text },

    funnel: {
      backgroundColor: c.surface,
      borderRadius: radius.lg,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      padding: space.md,
      gap: space.md,
    },
    // Sits above the bars, in the muted voice of context rather than the voice
    // of a measured stage.
    reach: { ...type.bodySm, color: c.muted },
    stage: { gap: space.xs },
    stageHead: { flexDirection: 'row', alignItems: 'baseline', gap: space.xs },
    stageValue: { ...type.display, color: c.text },
    stageLabel: { ...type.bodySm, color: c.muted, flex: 1 },
    track: { height: 6, borderRadius: radius.pill, backgroundColor: c.surface2, overflow: 'hidden' },
    bar: { height: '100%', borderRadius: radius.pill, backgroundColor: c.secondary },
    // The paid visit is the stage that matters — it is the only one that is
    // money, so it is the only one in the brand accent.
    barEmphasis: { backgroundColor: c.primary },

    earnedRow: {
      flexDirection: 'row',
      alignItems: 'baseline',
      gap: space.xs,
      borderTopWidth: StyleSheet.hairlineWidth,
      borderTopColor: c.border,
      paddingTop: space.md,
    },
    // `hero` is the one-per-screen headline, and on this screen that is the
    // money — it is the number people open the app for.
    earned: { ...type.hero, color: c.text },
    earnedLabel: { ...type.bodySm, color: c.muted },

    footnote: { ...type.bodySm, color: c.muted, lineHeight: 18 },

    section: { gap: space.xs },
    sectionTitle: {
      ...type.caption,
      fontWeight: '700',
      letterSpacing: 0.4,
      textTransform: 'uppercase',
      color: c.muted,
    },
    group: {
      backgroundColor: c.surface,
      borderRadius: radius.md,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      overflow: 'hidden',
    },
    row: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: space.sm,
      paddingHorizontal: space.md,
      paddingVertical: space.sm,
      borderBottomWidth: StyleSheet.hairlineWidth,
      borderBottomColor: c.border,
    },
    rowPressed: { opacity: 0.6 },
    rowText: { flex: 1, gap: space.xxs },
    rowTitle: { ...type.body, color: c.text, fontWeight: '600' },
    rowTitleMuted: { color: c.muted, fontWeight: '400' },
    rowMeta: { ...type.caption, color: c.muted },
    rowAmount: { ...type.bodyLg, color: c.text, fontVariant: ['tabular-nums'] },

    notice: { alignItems: 'center', gap: space.sm, paddingVertical: space.xl },
    noticeTitle: { ...type.bodyLg, color: c.text },
    noticeBody: { ...type.body, color: c.muted, textAlign: 'center', lineHeight: 21 },
  });
