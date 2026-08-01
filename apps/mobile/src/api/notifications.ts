/**
 * Notification center shapes (T-040).
 *
 * `type` is the stable MACHINE string from the server's `data.type`, never a
 * PHP class name — the client switches on it, so it is part of the contract.
 * The union is open on purpose: M4 will emit `redemption.*` and
 * `wallet.payout`, and the center must render an unknown-but-well-formed type
 * generically rather than dropping the row.
 */
export type KnownNotificationType =
  | 'share.published'
  | 'share.review_needed'
  | 'share.failed'
  | 'social.follow'
  | 'influencer.claim_rejected'
  // Defined now, emitted by M4 (T-043 / T-045) — declaring them here is what
  // lets the center's icon map be exhaustive before the senders exist.
  | 'redemption.verified'
  | 'wallet.payout';

export type NotificationRow = {
  id: string;
  /** A known type, or any other well-formed string from a newer server. */
  type: KnownNotificationType | (string & {});
  title: string | null;
  body: string | null;
  /** In-app deep-link path, handed straight to `router.push`. */
  url: string | null;
  data: Record<string, unknown>;
  read_at: string | null;
  created_at: string | null;
};

export type NotificationsPage = {
  data: NotificationRow[];
  meta: {
    unread_count: number;
    pagination: { next_cursor: string | null; prev_cursor: string | null; limit: number };
  };
};
