import { Ionicons } from '@expo/vector-icons';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { Stack } from 'expo-router';
import { useCallback, useMemo, useRef, useState } from 'react';
import { Linking, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useVenues } from '@/api/hooks/useOffers';
import { useVerifyRedemption } from '@/api/hooks/useRedemptions';
import type { VerifyOutcome } from '@/api/redemptions';
import { Button } from '@/components/button';
import { ScreenHeader } from '@/components/screen-header';
import { useT } from '@/i18n';
import { positionIfGranted } from '@/lib/location';
import { fonts, type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

/**
 * The till (T-047, 05 screen #20).
 *
 * Two ways in, both always available: scan the diner's QR, or type the code
 * they read out. The manual path is not a fallback for completeness — cameras
 * refuse to focus, permissions get denied, and a restaurant that cannot honour
 * an offer because of a lens is a restaurant that stops running offers.
 *
 * The scanner LOCKS after the first read until the result is dismissed. A
 * camera fires the same barcode many times a second; without the lock, one
 * customer's code becomes a dozen verify requests and trips the staff-velocity
 * limiter that exists to catch someone grinding through guesses.
 */
export default function VerifyScreen() {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  const { data: venues } = useVenues();
  const [permission, requestPermission] = useCameraPermissions();
  const verify = useVerifyRedemption();

  const [manualCode, setManualCode] = useState('');
  /*
   * The outcome carries the venue it was VERIFIED AGAINST, not whatever the
   * picker happens to show now. The picker stays live during the location
   * lookup and the request, so an operator who switches venues mid-flight would
   * otherwise get a result sheet naming the wrong restaurant — the one failure
   * this screen exists to make visible.
   */
  const [outcome, setOutcome] = useState<{ result: VerifyOutcome; venueName: string | null } | null>(null);
  const [venueId, setVenueId] = useState<string | null>(null);
  // A ref, not state: the camera can fire again before React re-renders, and a
  // state flag would let the second read through.
  const locked = useRef(false);

  /*
   * WHICH venue is being verified against, not "the first one we loaded".
   *
   * A code is bound to the place its offer belongs to, so an operator running
   * two restaurants who is defaulted to the wrong one has every code at their
   * second venue refused as `wrong_place` — a failure that looks like fraud and
   * is actually a picker that was never offered. With one venue there is
   * nothing to choose and no picker is shown.
   */
  const placeId = venueId ?? venues?.[0]?.id ?? null;

  /**
   * One verify, start to finish.
   *
   * The lock is released on EVERY path that does not end in a result sheet.
   * That is the whole contract: the sheet is the only thing that clears it by
   * hand, so a scan that gets discarded (venues still loading) or that throws
   * would otherwise leave a dead camera the operator can only fix by leaving
   * the screen — mid-service, with a queue.
   */
  const submit = useCallback(
    async (code: string) => {
      if (locked.current) return;
      if (placeId === null || code.trim() === '') return;

      locked.current = true;
      // Captured with the request, before any await can let the picker move.
      const submittedVenueName = venues?.find((venue) => venue.id === placeId)?.name ?? null;

      try {
        // Best-effort AND time-boxed, through the shared helper: a phone that
        // cannot get a fix must not stop a customer being served, and
        // `getCurrentPositionAsync` takes no timeout — indoors it simply never
        // returns. The server records an unknown location and lets the
        // verification through (06 §3).
        const region = await positionIfGranted(FIX_BUDGET_MS);

        const result = await verify.mutateAsync({
          code: code.trim(),
          placeId,
          lat: region?.latitude,
          lng: region?.longitude,
        });

        setOutcome({ result, venueName: submittedVenueName });
      } catch {
        // `useVerifyRedemption` returns refusals as data, so reaching here means
        // something unexpected broke. Unlock rather than strand the scanner.
        locked.current = false;
      }
    },
    [placeId, venues, verify],
  );

  const onScan = useCallback(({ data }: { data: string }) => void submit(data), [submit]);

  const reset = () => {
    setOutcome(null);
    setManualCode('');
    locked.current = false;
  };

  // The lock guards the typed path too: a second press, or a press followed by
  // the keyboard's submit, is the same duplicate the velocity limiter reads as
  // someone guessing codes.
  const submitManual = () => void submit(manualCode);

  if (venues !== undefined && venues.length === 0) {
    return (
      <Shell styles={styles} title={t('scan.title')}>
        <View style={styles.centered}>
          <Ionicons name="storefront-outline" size={40} color={c.muted} />
          <Text style={styles.hint}>{t('scan.noVenue')}</Text>
        </View>
      </Shell>
    );
  }

  if (outcome !== null) {
    return (
      <Shell styles={styles} title={t('scan.title')}>
        <ResultSheet
          outcome={outcome.result}
          styles={styles}
          c={c}
          onNext={reset}
          venueName={venues !== undefined && venues.length > 1 ? outcome.venueName : null}
        />
      </Shell>
    );
  }

  return (
    <Shell styles={styles} title={t('scan.title')}>
      {venues !== undefined && venues.length > 1 ? (
        <View style={styles.venues} testID="verify-venue-picker">
          <Text style={styles.manualLabel}>{t('scan.venue')}</Text>
          <View style={styles.venueRow}>
            {venues.map((venue) => (
              <Pressable
                key={venue.id}
                accessibilityRole="radio"
                accessibilityState={{ selected: venue.id === placeId }}
                onPress={() => setVenueId(venue.id)}
                style={[styles.venueChip, venue.id === placeId && styles.venueChipActive]}
                testID={`verify-venue-${venue.id}`}
              >
                <Text style={[styles.venueLabel, venue.id === placeId && styles.venueLabelActive]}>
                  {venue.name}
                </Text>
              </Pressable>
            ))}
          </View>
        </View>
      ) : null}

      <View style={styles.cameraWrap}>
        {permission?.granted ? (
          <CameraView
            style={styles.camera}
            facing="back"
            // QR only. Letting the scanner match every symbology it knows means
            // a product barcode on the table triggers a verify.
            barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
            onBarcodeScanned={verify.isPending ? undefined : onScan}
            testID="verify-camera"
          />
        ) : (
          <View style={styles.cameraFallback}>
            <Ionicons name="camera-outline" size={40} color={c.muted} />
            <Text style={styles.hint}>
              {permission?.canAskAgain === false ? t('scan.cameraBlocked') : t('scan.cameraNeeded')}
            </Text>
            <Button
              title={permission?.canAskAgain === false ? t('scan.openSettings') : t('scan.allowCamera')}
              variant="secondary"
              onPress={() => {
                if (permission?.canAskAgain === false) void Linking.openSettings();
                else void requestPermission();
              }}
              testID="verify-permission-cta"
            />
          </View>
        )}
      </View>

      {/* Always present, never behind a "having trouble?" disclosure — at a
          busy counter the typed path is often simply faster. */}
      <View style={styles.manual}>
        <Text style={styles.manualLabel}>{t('scan.orType')}</Text>
        <TextInput
          style={styles.input}
          value={manualCode}
          onChangeText={(text) => setManualCode(text.toUpperCase())}
          placeholder="7F3K-92QX-AB"
          placeholderTextColor={c.placeholder}
          autoCapitalize="characters"
          autoCorrect={false}
          returnKeyType="done"
          onSubmitEditing={submitManual}
          testID="verify-manual-input"
        />
        <Button
          title={t('scan.check')}
          onPress={submitManual}
          loading={verify.isPending}
          disabled={manualCode.trim() === '' || verify.isPending}
          testID="verify-submit"
        />
      </View>
    </Shell>
  );
}

function Shell({
  children,
  styles,
  title,
}: {
  children: React.ReactNode;
  styles: ReturnType<typeof makeStyles>;
  title: string;
}) {
  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <Stack.Screen options={{ headerShown: false }} />
      <ScreenHeader title={title} divided />
      <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
        {children}
      </ScrollView>
    </SafeAreaView>
  );
}

/**
 * What happened, in the terms someone at a till needs.
 *
 * `already_redeemed` never reaches here as a failure — the API replays the
 * prior result at 200, and the sheet says "already verified" in GREEN. To staff
 * that is a success with a note, not a rejection; rendering it red would have
 * them turning away a customer who already paid.
 */
function ResultSheet({
  outcome,
  styles,
  c,
  onNext,
  venueName,
}: {
  outcome: VerifyOutcome;
  styles: ReturnType<typeof makeStyles>;
  c: Palette;
  onNext: () => void;
  /** Only passed when the operator runs more than one venue — otherwise noise. */
  venueName?: string | null;
}) {
  const t = useT();

  if (outcome.kind === 'verified') {
    return (
      <View style={styles.centered} testID="verify-result-success">
        <View style={[styles.badge, { backgroundColor: c.greenSoft }]}>
          <Ionicons name={outcome.replayed ? 'checkmark-done' : 'checkmark-circle'} size={56} color={c.green} />
        </View>
        <Text style={styles.headline}>
          {outcome.replayed ? t('scan.alreadyVerified') : t('scan.valid')}
        </Text>
        {outcome.replayed ? <Text style={styles.hint}>{t('scan.alreadyVerifiedBody')}</Text> : null}
        {/* The terms the diner agreed to, so staff honour the right thing. */}
        {outcome.redemption.offer?.title ? (
          <Text style={styles.offerTitle}>{outcome.redemption.offer.title}</Text>
        ) : null}
        {outcome.redemption.offer?.terms ? (
          <Text style={styles.terms}>{outcome.redemption.offer.terms}</Text>
        ) : null}
        {venueName ? <Text style={styles.terms}>{venueName}</Text> : null}
        <Button title={t('scan.scanNext')} onPress={onNext} testID="verify-next" />
      </View>
    );
  }

  return (
    <View style={styles.centered} testID="verify-result-failure">
      <View style={[styles.badge, { backgroundColor: c.dangerSoft }]}>
        <Ionicons name="close-circle" size={56} color={c.danger} />
      </View>
      <Text style={styles.headline}>{t(FAILURE_TITLE[outcome.reason] ?? 'scan.fail.unknown')}</Text>
      <Text style={styles.hint}>{t(FAILURE_BODY[outcome.reason] ?? 'scan.fail.unknownBody')}</Text>
      <Button title={t('scan.tryAnother')} variant="secondary" onPress={onNext} testID="verify-next" />
    </View>
  );
}

/**
 * A wrong venue and an already-used code are a mistake and a possible fraud
 * attempt respectively — they read very differently across a counter, so each
 * gets its own words rather than a shared "invalid".
 */
const FAILURE_TITLE = {
  not_found: 'scan.fail.notFound',
  expired: 'scan.fail.expired',
  wrong_place: 'scan.fail.wrongPlace',
  not_live: 'scan.fail.notLive',
  outside_geofence: 'scan.fail.geofence',
  staff_velocity_exceeded: 'scan.fail.velocity',
  unknown: 'scan.fail.unknown',
} as const;

const FAILURE_BODY = {
  not_found: 'scan.fail.notFoundBody',
  expired: 'scan.fail.expiredBody',
  wrong_place: 'scan.fail.wrongPlaceBody',
  not_live: 'scan.fail.notLiveBody',
  outside_geofence: 'scan.fail.geofenceBody',
  staff_velocity_exceeded: 'scan.fail.velocityBody',
  unknown: 'scan.fail.unknownBody',
} as const;

/**
 * How long the till waits for a fix before verifying without one. Shorter than
 * the map's budget: a queue is forming, and the geofence is a signal the server
 * already tolerates being absent.
 */
const FIX_BUDGET_MS = 3_000;

/** Big enough to read across a counter at arm's length. */
const BADGE = 96;

/**
 * Outside the type ramp on purpose. Staff type this code one character at a
 * time from someone reading it aloud, which needs wider tracking and a larger
 * glyph than any body step — bending the scale to fit would distort every
 * other use of it.
 */
const INPUT_SIZE = 20;

const INPUT_TRACKING = 2;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    safe: { flex: 1, backgroundColor: c.background },
    scroll: { flexGrow: 1, padding: space.md, gap: space.md },
    centered: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: space.md, paddingVertical: space.xl },
    badge: { width: BADGE, height: BADGE, borderRadius: radius.pill, alignItems: 'center', justifyContent: 'center' },
    headline: { ...type.title, fontFamily: fonts.display, color: c.text, textAlign: 'center' },
    offerTitle: { ...type.bodyLg, color: c.text, textAlign: 'center' },
    terms: { ...type.bodySm, color: c.muted, textAlign: 'center', paddingHorizontal: space.md },
    hint: { ...type.body, color: c.muted, textAlign: 'center', paddingHorizontal: space.md },

    cameraWrap: { aspectRatio: 1, borderRadius: radius.lg, overflow: 'hidden', backgroundColor: c.surface2 },
    camera: { flex: 1 },
    cameraFallback: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: space.sm, padding: space.md },

    venues: { gap: space.xs },
    venueRow: { flexDirection: 'row', flexWrap: 'wrap', gap: space.xs },
    venueChip: {
      paddingHorizontal: space.sm,
      paddingVertical: space.xs,
      borderRadius: radius.pill,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.line2,
      backgroundColor: c.surface,
    },
    venueChipActive: { backgroundColor: c.text, borderColor: c.text },
    venueLabel: { ...type.bodySm, color: c.ink2 },
    venueLabelActive: { color: c.background, fontWeight: '600' },

    manual: { gap: space.sm },
    manualLabel: { ...type.caption, color: c.muted, textTransform: 'uppercase', letterSpacing: 0.6 },
    input: {
      borderWidth: 1,
      borderColor: c.line2,
      borderRadius: radius.md,
      paddingHorizontal: space.md,
      paddingVertical: space.sm,
      fontFamily: fonts.mono,
      fontSize: INPUT_SIZE,
      letterSpacing: INPUT_TRACKING,
      color: c.text,
      backgroundColor: c.surface,
      textAlign: 'center',
    },
  });
