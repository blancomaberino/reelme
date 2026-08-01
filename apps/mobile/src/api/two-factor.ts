/**
 * Two-factor authentication shapes (T-068).
 *
 * The setup payload is returned once, by `POST /two-factor/enable`, and the
 * recovery codes once more by `POST /two-factor/confirm`. Neither is refetched
 * on a whim: re-reading the codes costs a password.
 */

/** State of the caller's second factor (GET /two-factor). */
export type TwoFactorStatus = {
  enabled: boolean;
  /** Setup was started but never confirmed — the UI offers Continue, not Start. */
  pending: boolean;
  confirmed_at: string | null;
  recovery_codes_remaining: number;
};

/** The one-time setup payload (POST /two-factor/enable). */
export type TwoFactorSetup = {
  /** Base-32 secret, for an authenticator that cannot scan. */
  secret: string;
  otpauth_uri: string;
  /**
   * The QR as a PNG data URI, rendered server-side.
   *
   * Deliberately not drawn on the device: every React Native QR library goes
   * through react-native-svg, which is a native module and would force a full
   * dev-client rebuild. A ~1KB data URI renders in the stock `Image`.
   */
  qr_png: string;
};

export type RecoveryCodes = { recovery_codes: string[] };
