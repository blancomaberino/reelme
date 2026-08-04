import { useMemo } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import QRCode from 'react-native-qrcode-svg';

import { useT } from '@/i18n';
import { fonts, type Palette, useColors } from '@/theme/colors';
import { radius, space, type } from '@/theme/tokens';

type Props = {
  /** The signed payload (`v1.<code>.<hmac>`) — what the scanner reads. */
  payload: string;
  /** The grouped human form (`7F3K-92QX-AB`) — what gets read aloud. */
  code: string;
};

/**
 * The two ways a code can reach a till (T-047, 05 screen #18).
 *
 * The QR carries the SIGNED payload, so a scan is unforgeable; the typed code
 * is the same redemption in a form a person can say out loud when the camera
 * will not focus. Both are always shown, because a restaurant with a broken
 * scanner should not be a restaurant that cannot honour an offer.
 *
 * The QR is drawn on a WHITE card regardless of theme. Scanners need the
 * contrast, and a dark-mode QR on a dark background is the classic way to ship
 * a code nothing can read.
 */
export function RedemptionCode({ payload, code }: Props) {
  const c = useColors();
  const t = useT();
  const styles = useMemo(() => makeStyles(c), [c]);

  return (
    <View style={styles.wrap}>
      <View style={styles.qrCard} accessibilityLabel={t('redeem.qrAlt')} testID="redemption-qr">
        {payload !== '' ? (
          <QRCode value={payload} size={QR_SIZE} backgroundColor="#FFFFFF" color="#000000" />
        ) : null}
      </View>

      <Text style={styles.orLabel}>{t('redeem.orReadOut')}</Text>
      {/* Monospaced and widely tracked: this gets read character by character
          across a counter, and an O that looks like a 0 is a failed redemption
          even though the code itself is fine. */}
      <Text style={styles.code} testID="redemption-code" accessibilityLabel={code.split('').join(' ')}>
        {code}
      </Text>
    </View>
  );
}

const QR_SIZE = 220;

/**
 * Deliberately outside the type ramp. This string is read CHARACTER BY
 * CHARACTER across a counter, which is a different job from body copy — the
 * scale has no step for "legible when dictated", and forcing one would make
 * every other size wrong.
 */
const CODE_SIZE = 26;

const CODE_TRACKING = 3;

const makeStyles = (c: Palette) =>
  StyleSheet.create({
    wrap: { alignItems: 'center', gap: space.sm },
    qrCard: {
      backgroundColor: '#FFFFFF',
      padding: space.md,
      borderRadius: radius.lg,
      borderWidth: StyleSheet.hairlineWidth,
      borderColor: c.border,
    },
    orLabel: { ...type.caption, color: c.muted, textTransform: 'uppercase', letterSpacing: 0.6 },
    code: { fontFamily: fonts.mono, fontSize: CODE_SIZE, letterSpacing: CODE_TRACKING, color: c.text },
  });
