import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '../client';
import { queryKeys } from '../keys';
import {
  codeState,
  refusalReason,
  type Redemption,
  type VerifyFailureReason,
  type VerifyOutcome,
} from '../redemptions';
import { ValidationError } from '../types';

/**
 * Issue a code for an offer (T-047 → T-043).
 *
 * `share_id` is the REFERRAL CONTEXT — which share the diner navigated from —
 * and it is what T-043 freezes onto the row as attribution. Threading it from
 * the route rather than guessing server-side is the whole reason the influencer
 * who actually sent someone gets paid.
 *
 * Carries an `Idempotency-Key` per 03 §1. Note the ISSUE endpoint does not read
 * it today — the anti-fraud unique index on (offer_id, user_id) is what stops a
 * retry minting a second code, and it reports that as `already_issued`. The
 * header is sent so the endpoint can honour it without a client release; the
 * screen's recovery is {@see useLiveRedemptionForOffer}, which turns that
 * refusal back into the code the diner already holds.
 */
export function useIssueRedemption() {
  const qc = useQueryClient();

  return useMutation({
    // Never retried automatically (05 §state rules): this mints a bearer token,
    // and a silent retry is exactly what the idempotency key exists to survive
    // — not something to do casually.
    retry: 0,
    mutationFn: async (input: { offerId: string; shareId?: string | null }): Promise<Redemption> => {
      const { data } = await api.post<{ data: Redemption }>(
        '/redemptions',
        { offer_id: Number(input.offerId), share_id: input.shareId ? Number(input.shareId) : undefined },
        { headers: { 'Idempotency-Key': `redeem-${input.offerId}-${Date.now()}` } },
      );
      return data.data;
    },
    onSuccess: (redemption) => {
      qc.setQueryData(queryKeys.redemption(redemption.id), redemption);
      void qc.invalidateQueries({ queryKey: queryKeys.myRedemptions() });
    },
  });
}

/**
 * The caller's still-live code for one offer, if they already hold one.
 *
 * The server refuses a second code with `already_issued` and does NOT say which
 * one — the anti-fraud unique index has no reason to. Without this the diner is
 * told "you already have a code" and given no way to reach it, which is a dead
 * end at the exact moment they are standing in the restaurant. Enabled only
 * once that refusal actually happens, so the normal path costs nothing.
 */
export function useLiveRedemptionForOffer(offerId: string, options: { enabled: boolean }) {
  return useQuery({
    queryKey: [...queryKeys.myRedemptions(), 'live', offerId],
    queryFn: async (): Promise<Redemption | null> => {
      const { data } = await api.get<{ data: Redemption[] }>('/me/redemptions', { params: { limit: 50 } });

      return (
        data.data.find(
          (row) => row.offer_id === offerId && row.status === 'issued' && codeState(row) === 'active',
        ) ?? null
      );
    },
    enabled: options.enabled,
    staleTime: 0,
  });
}

/**
 * Watch a code until it is used.
 *
 * Polls while it is still live, because the diner is standing at a counter and
 * the screen has to flip to "done" without them refreshing. Stops once the code
 * reaches a terminal state — a redeemed code cannot change again, so continuing
 * to poll would be battery spent on a settled question.
 */
export function useRedemption(id: string | null, options: { poll?: boolean } = {}) {
  return useQuery({
    queryKey: queryKeys.redemption(id ?? ''),
    queryFn: async (): Promise<Redemption> => {
      const { data } = await api.get<{ data: Redemption }>(`/redemptions/${encodeURIComponent(id as string)}`);
      return data.data;
    },
    enabled: !!id,
    staleTime: 0,
    refetchInterval: (query) => {
      if (options.poll === false) return false;
      const status = query.state.data?.status;

      return status === 'issued' ? 3000 : false;
    },
  });
}

/**
 * Verify a code at the till (T-047 → T-043).
 *
 * Never auto-retried: the server is exactly-once, but a retry that races the
 * first request makes the staff-velocity limiter fire for one honest scan.
 */
export function useVerifyRedemption() {
  const qc = useQueryClient();

  return useMutation({
    retry: 0,
    mutationFn: async (input: {
      code: string;
      placeId: string;
      lat?: number | null;
      lng?: number | null;
    }): Promise<VerifyOutcome> => {
      try {
        const { data } = await api.post<{ data: Redemption; meta: { replayed: boolean } }>(
          '/redemptions/verify',
          {
            code: input.code,
            place_id: Number(input.placeId),
            lat: input.lat ?? undefined,
            lng: input.lng ?? undefined,
          },
        );

        return { kind: 'verified', redemption: data.data, replayed: data.meta.replayed };
      } catch (error) {
        return { kind: 'failed', ...failureFrom(error) };
      }
    },
    onSuccess: () => void qc.invalidateQueries({ queryKey: queryKeys.placeRedemptions() }),
  });
}

/**
 * The API's machine-readable reason, or `unknown`.
 *
 * Returned as data rather than thrown: every one of these is a normal outcome
 * at a till — a wrong venue, a lapsed code — and the result sheet renders them
 * side by side with success. Throwing would push presentation logic into an
 * error boundary.
 */
function failureFrom(error: unknown): { reason: VerifyFailureReason | 'unknown'; distanceM?: number } {
  const raw = refusalReason(error);
  const distance = distanceFrom(error);

  // Narrowed against the known set rather than cast: a reason the app has no
  // copy for must render as the generic failure, not as an empty sheet whose
  // label happens to be a string the server invented.
  const reason = raw !== null && (KNOWN_FAILURES as readonly string[]).includes(raw)
    ? (raw as VerifyFailureReason)
    : 'unknown';

  return { reason, distanceM: distance };
}

const KNOWN_FAILURES = [
  'not_found',
  'expired',
  'wrong_place',
  'not_live',
  'outside_geofence',
  'staff_velocity_exceeded',
] as const satisfies readonly VerifyFailureReason[];

/**
 * How far off the venue the scan was — `outside_geofence` is a 422, so the
 * number arrives stringified in a `ValidationError`'s fields rather than as the
 * integer the raw envelope carries.
 */
function distanceFrom(error: unknown): number | undefined {
  if (error instanceof ValidationError) {
    const parsed = Number(error.fields.distance_m);
    return Number.isFinite(parsed) ? parsed : undefined;
  }

  const raw = (error as { response?: { data?: { error?: { details?: { distance_m?: unknown } } } } })
    ?.response?.data?.error?.details?.distance_m;

  return typeof raw === 'number' ? raw : undefined;
}
