// Redemption types (T-047) — POST /redemptions, GET /redemptions/{id},
// POST /redemptions/verify.
//
// Re-exported from @reelmap/contracts where a schema exists, so a renamed API
// field breaks `tsc` rather than the device.
import type { Redemption as ContractRedemption } from '@reelmap/contracts';

import { ValidationError } from './types';

export type Redemption = ContractRedemption;

export type RedemptionStatus = Redemption['status'];

/**
 * What the diner's code screen is currently showing.
 *
 * Derived from the server's `status` AND the clock, never from `status` alone:
 * the expiry sweep runs on a schedule, so a code whose window closed at 3am
 * still reads `issued` until it catches up. Offering that to a till is a
 * customer being told "no" at the counter.
 */
export type CodeState = 'active' | 'verified' | 'expired' | 'void';

export function codeState(redemption: Redemption, now: Date = new Date()): CodeState {
  if (redemption.status === 'redeemed') return 'verified';
  if (redemption.status === 'void') return 'void';
  if (redemption.status === 'expired') return 'expired';

  const expiresAt = redemption.expires_at ? new Date(redemption.expires_at).getTime() : null;

  return expiresAt !== null && expiresAt <= now.getTime() ? 'expired' : 'active';
}

/** Whole seconds until the code lapses; 0 once it has. Never negative. */
export function secondsRemaining(redemption: Redemption, now: Date = new Date()): number {
  if (!redemption.expires_at) return 0;

  return Math.max(0, Math.floor((new Date(redemption.expires_at).getTime() - now.getTime()) / 1000));
}

/** `23h 41m` / `41m 12s` / `12s` — coarse far out, precise when it matters. */
export function formatRemaining(seconds: number): string {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);

  if (hours > 0) return `${hours}h ${minutes}m`;
  if (minutes > 0) return `${minutes}m ${seconds % 60}s`;

  return `${seconds}s`;
}

/**
 * Why a verification failed, as the API reports it (03 §3.4).
 *
 * `already_redeemed` is deliberately NOT in the failure set the UI treats as an
 * error — the API replays the prior result at 200, and to staff "you already
 * scanned this" is a success with a note, not a rejection.
 */
export type VerifyFailureReason =
  | 'not_found'
  | 'expired'
  | 'wrong_place'
  | 'not_live'
  | 'outside_geofence'
  | 'staff_velocity_exceeded';

/** The outcome of a scan, as the result sheet needs it. */
export type VerifyOutcome =
  | { kind: 'verified'; redemption: Redemption; replayed: boolean }
  | { kind: 'failed'; reason: VerifyFailureReason | 'unknown'; distanceM?: number };

/**
 * Why a code could not be ISSUED (06 §3 anti-fraud). Each maps to different
 * advice — "come back tomorrow" and "you already have one" are not the same
 * instruction, and a single "could not redeem" teaches people to keep tapping.
 */
export type IssueFailureReason =
  | 'offer_not_redeemable'
  | 'already_issued'
  | 'user_quota_reached'
  | 'velocity_exceeded'
  | 'cooldown'
  | 'self_dealing';

/**
 * The machine-readable `reason` behind a refusal, whatever shape it arrived in.
 *
 * Two shapes, because the client's response interceptor rewrites a 422 into a
 * `ValidationError` whose `fields` carry the details — while 403/409/429
 * refusals (self-dealing, already-issued, velocity) reach here as the raw axios
 * error. 06 §3 spreads the anti-fraud reasons across ALL of those statuses, so
 * a reader that handled only one shape would answer half the refusals with the
 * generic "something went wrong" and leave the diner tapping the button again.
 */
export function refusalReason(error: unknown): string | null {
  if (error instanceof ValidationError) {
    return typeof error.fields.reason === 'string' ? error.fields.reason : null;
  }

  const raw = (error as { response?: { data?: { error?: { details?: { reason?: unknown } } } } })
    ?.response?.data?.error?.details?.reason;

  return typeof raw === 'string' ? raw : null;
}
