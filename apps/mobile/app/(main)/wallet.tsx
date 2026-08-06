import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useMemo, useState } from 'react';
import { ActivityIndicator, Alert, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useOnboardingLink, useRequestPayout, useWallet, useWalletLedger } from '@/api/hooks/useWallet';
import { formatMoney, needsAttention, type WalletEntry } from '@/api/wallet';
import { Button } from '@/components/button';
import { useT } from '@/i18n';
import { openStripeOnboarding } from '@/lib/stripe-onboarding';
import { useFormat } from '@/lib/use-format';
import { fonts, type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * The influencer's money (T-046, 05 screen #21).
 *
 * Two numbers, and the difference between them is the whole screen: what you
 * can cash out NOW, and what is already on its way. Showing one figure would
 * make a requested payout look like money that vanished.
 *
 * The Connect banner is not decoration. Stripe re-verifies, so an account that
 * onboarded months ago can silently stop being payable — and the first time
 * anyone would notice is a failed cash-out. Surfacing `requirements_due` turns
 * that into something the influencer can act on before they try.
 */
export default function WalletScreen() {
  const c = useColors();
  const t = useT();
  const fmt = useFormat();
  const styles = useMemo(() => makeStyles(c), [c]);

  const wallet = useWallet();
  const ledger = useWalletLedger();
  const payout = useRequestPayout();
  const onboarding = useOnboardingLink();
  const [openingStripe, setOpeningStripe] = useState(false);

  const entries = useMemo(
    () => (ledger.data?.pages ?? []).flatMap((page) => page.data),
    [ledger.data],
  );

  const startOnboarding = () => {
    setOpeningStripe(true);
    onboarding.mutate(undefined, {
      onSuccess: async (url) => {
        await openStripeOnboarding(url);
        setOpeningStripe(false);
        // The session closes on the return redirect; re-read rather than assume
        // it succeeded — the user may have abandoned it halfway.
        void wallet.refetch();
      },
      onError: () => setOpeningStripe(false),
    });
  };

  const confirmPayout = () => {
    const available = wallet.data?.balance.available;
    if (!available) return;

    Alert.alert(
      t('wallet.payout.confirmTitle'),
      t('wallet.payout.confirmBody', { amount: formatMoney(available) }),
      [
        { text: t('common.cancel'), style: 'cancel' },
        {
          text: t('wallet.payout.confirm'),
          onPress: () =>
            payout.mutate(undefined, {
              onError: () => Alert.alert(t('wallet.payout.failedTitle'), t('common.error.general')),
            }),
        },
      ],
    );
  };

  if (wallet.isLoading) {
    return (
      <SafeAreaView style={styles.safe} edges={['top']}>
        <ActivityIndicator color={c.primary} style={styles.loading} accessibilityLabel={t('common.loading')} />
      </SafeAreaView>
    );
  }

  if (wallet.isError || !wallet.data) {
    return (
      <SafeAreaView style={styles.safe} edges={['top']}>
        <View style={styles.empty}>
          <Ionicons name="cloud-offline-outline" size={40} color={c.muted} />
          <Text style={styles.emptyText}>{t('common.error.general')}</Text>
          <Button title={t('common.tryAgain')} variant="secondary" onPress={() => void wallet.refetch()} />
        </View>
      </SafeAreaView>
    );
  }

  const data = wallet.data;
  const attention = needsAttention(data.connect);

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <FlatList
        data={entries}
        keyExtractor={(entry) => entry.id}
        contentContainerStyle={styles.list}
        refreshControl={
          <RefreshControl
            refreshing={wallet.isRefetching}
            onRefresh={() => {
              void wallet.refetch();
              void ledger.refetch();
            }}
            tintColor={c.primary}
          />
        }
        onEndReached={() => {
          if (ledger.hasNextPage && !ledger.isFetchingNextPage) void ledger.fetchNextPage();
        }}
        onEndReachedThreshold={0.4}
        ListHeaderComponent={
          <View style={styles.header}>
            <Text style={styles.title}>{t('wallet.title')}</Text>

            <View style={styles.balanceCard}>
              <Text style={styles.balanceLabel}>{t('wallet.available')}</Text>
              <Text style={styles.balance} testID="wallet-available">
                {formatMoney(data.balance.available)}
              </Text>

              {data.balance.pending.amount > 0 ? (
                <View style={styles.pendingRow}>
                  <Ionicons name="time-outline" size={14} color={c.muted} />
                  {/* Without this line a requested payout looks like money that
                      disappeared between two visits to the screen. */}
                  <Text style={styles.pending} testID="wallet-pending">
                    {t('wallet.pending', { amount: formatMoney(data.balance.pending) })}
                  </Text>
                </View>
              ) : null}

              <View style={styles.divider} />

              <View style={styles.metaRow}>
                <Text style={styles.metaLabel}>{t('wallet.lifetime')}</Text>
                <Text style={styles.metaValue}>{formatMoney(data.lifetime_earnings)}</Text>
              </View>
              {data.fees_owed ? (
                <View style={styles.metaRow}>
                  <Text style={styles.metaLabel}>{t('wallet.feesOwed')}</Text>
                  <Text style={styles.metaValue}>{formatMoney(data.fees_owed)}</Text>
                </View>
              ) : null}
            </View>

            {attention ? (
              <View style={styles.banner} testID="wallet-connect-banner">
                <Ionicons name="shield-outline" size={20} color={c.gold} />
                <View style={styles.bannerBody}>
                  <Text style={styles.bannerTitle}>
                    {data.connect.onboarded ? t('wallet.connect.actionNeeded') : t('wallet.connect.setUp')}
                  </Text>
                  <Text style={styles.bannerText}>
                    {data.connect.onboarded ? t('wallet.connect.actionNeededBody') : t('wallet.connect.setUpBody')}
                  </Text>
                </View>
                <Button
                  title={t('wallet.connect.cta')}
                  variant="secondary"
                  size="sm"
                  loading={openingStripe || onboarding.isPending}
                  onPress={startOnboarding}
                  testID="wallet-connect-cta"
                />
              </View>
            ) : null}

            <Button
              title={t('wallet.payout.cta')}
              icon="cash-outline"
              // Server-computed: "enough money AND Stripe will accept it". A
              // client deriving it from the two fields would drift the day
              // either rule changes.
              disabled={!data.can_request_payout}
              loading={payout.isPending}
              onPress={confirmPayout}
              testID="wallet-payout-cta"
            />
            {!data.can_request_payout && !attention ? (
              <Text style={styles.hint}>
                {t('wallet.payout.belowThreshold', { amount: formatMoney(data.minimum_payout) })}
              </Text>
            ) : null}

            {/* The other half of the same question (T-048): this screen says
                how much, the dashboard says which posts earned it. Placed above
                the statement because "where did this come from" is the thought
                the balance provokes, and a per-entry ledger answers it one
                euro at a time. */}
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={t('earnings.entry')}
              onPress={() => router.push('/influencer/dashboard')}
              style={({ pressed }) => [styles.earningsRow, pressed && styles.earningsRowPressed]}
              testID="wallet-earnings-entry"
            >
              <Ionicons name="stats-chart-outline" size={20} color={c.primary} />
              <View style={styles.earningsText}>
                <Text style={styles.earningsTitle}>{t('earnings.entry')}</Text>
                <Text style={styles.earningsHint}>{t('earnings.entryHint')}</Text>
              </View>
              <Ionicons name="chevron-forward" size={18} color={c.muted} />
            </Pressable>

            <Text style={styles.sectionTitle}>{t('wallet.history')}</Text>
          </View>
        }
        renderItem={({ item }) => <EntryRow entry={item} styles={styles} c={c} label={t(TYPE_LABEL[item.type])} date={fmt.date(item.created_at)} />}
        ListEmptyComponent={
          <View style={styles.empty}>
            <Ionicons name="receipt-outline" size={36} color={c.muted} />
            <Text style={styles.emptyText}>{t('wallet.noHistory')}</Text>
          </View>
        }
        ListFooterComponent={
          ledger.isFetchingNextPage ? <ActivityIndicator color={c.primary} style={styles.footer} /> : null
        }
      />
    </SafeAreaView>
  );
}

function EntryRow({
  entry,
  styles,
  c,
  label,
  date,
}: {
  entry: WalletEntry;
  styles: ReturnType<typeof makeStyles>;
  c: Palette;
  label: string;
  date: string;
}) {
  const isCredit = entry.direction === 'credit';

  return (
    <View style={styles.entry}>
      <View style={[styles.entryIcon, { backgroundColor: isCredit ? c.greenSoft : c.surface2 }]}>
        <Ionicons
          name={isCredit ? 'arrow-down' : 'arrow-up'}
          size={16}
          color={isCredit ? c.green : c.ink2}
        />
      </View>
      <View style={styles.entryBody}>
        <Text style={styles.entryLabel} numberOfLines={1}>
          {label}
        </Text>
        {entry.memo ? (
          <Text style={styles.entryMemo} numberOfLines={1}>
            {entry.memo}
          </Text>
        ) : null}
        <Text style={styles.entryDate}>{date}</Text>
      </View>
      {/* Signed for the reader: the ledger stores a positive amount and a
          direction, but "+€1.50" and "−€1.50" is what a statement means. */}
      <Text style={[styles.entryAmount, isCredit ? styles.entryCredit : styles.entryDebit]}>
        {isCredit ? '+' : '−'}
        {formatMoney({ amount: entry.amount, currency: entry.currency })}
      </Text>
    </View>
  );
}

const TYPE_LABEL = {
  revenue_share: 'wallet.type.revenueShare',
  escrow_release: 'wallet.type.escrowRelease',
  payout: 'wallet.type.payout',
  adjustment: 'wallet.type.adjustment',
} as const;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    loading: { paddingVertical: space.xxl },
    list: { paddingBottom: space.xxl },
    footer: { paddingVertical: space.md },
    header: { padding: space.md, gap: space.md },
    title: { ...type.display, color: c.text },

    balanceCard: {
      backgroundColor: c.surface,
      borderRadius: radius.lg,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      padding: space.md,
      gap: space.xxs,
    },
    balanceLabel: { ...type.caption, color: c.muted, textTransform: 'uppercase', letterSpacing: 0.6 },
    balance: { ...type.hero, fontFamily: fonts.display, color: c.text },
    pendingRow: { flexDirection: 'row', alignItems: 'center', gap: space.xxs },
    pending: { ...type.bodySm, color: c.muted },
    divider: { height: StyleSheet.hairlineWidth, backgroundColor: c.border, marginVertical: space.xs },
    metaRow: { flexDirection: 'row', justifyContent: 'space-between' },
    metaLabel: { ...type.bodySm, color: c.muted },
    metaValue: { ...type.bodySm, color: c.ink2, fontWeight: '600' },

    banner: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: space.sm,
      backgroundColor: c.goldSoft,
      borderRadius: radius.md,
      padding: space.sm,
      flexWrap: 'wrap',
    },
    bannerBody: { flex: 1, gap: space.xxs, minWidth: 160 },
    bannerTitle: { ...type.bodyLg, color: c.text },
    bannerText: { ...type.bodySm, color: c.ink2 },

    hint: { ...type.bodySm, color: c.muted, textAlign: 'center' },
    sectionTitle: { ...type.bodyLg, fontFamily: fonts.display, color: c.text, marginTop: space.xs },

    entry: { flexDirection: 'row', alignItems: 'center', gap: space.sm, paddingHorizontal: space.md, paddingVertical: space.sm },
    entryIcon: { width: 32, height: 32, borderRadius: radius.pill, alignItems: 'center', justifyContent: 'center' },
    entryBody: { flex: 1, gap: space.xxs },
    entryLabel: { ...type.body, color: c.text, fontWeight: '600' },
    entryMemo: { ...type.bodySm, color: c.muted },
    entryDate: { ...type.caption, color: c.muted },
    entryAmount: { ...type.bodyLg },
    entryCredit: { color: c.green },
    entryDebit: { color: c.ink2 },

    earningsRow: {
      flexDirection: 'row',
      alignItems: 'center',
      gap: space.sm,
      backgroundColor: c.surface,
      borderRadius: radius.md,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
      paddingHorizontal: space.md,
      paddingVertical: space.sm,
    },
    earningsRowPressed: { opacity: 0.6 },
    earningsText: { flex: 1, gap: space.xxs },
    earningsTitle: { ...type.body, color: c.text, fontWeight: '600' },
    earningsHint: { ...type.caption, color: c.muted },

    empty: { alignItems: 'center', gap: space.sm, paddingTop: space.xl, paddingHorizontal: space.xl },
    emptyText: { ...type.body, color: c.muted, textAlign: 'center' },
  });
