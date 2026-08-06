import type { Notification } from '@reelmap/contracts';

/**
 * Notification center shapes (T-040).
 *
 * `type` is the stable MACHINE string from the server's `data.type`, never a
 * PHP class name — the client switches on it, so it is part of the contract.
 * The union is open on purpose: the server may emit a type this build has never
 * heard of, and the center must render it generically rather than drop the row.
 */
export type KnownNotificationType =
  | 'share.published'
  | 'share.review_needed'
  | 'share.failed'
  | 'social.follow'
  | 'influencer.claim_rejected'
  | 'redemption.verified'
  | 'wallet.payout';

/**
 * One row, structurally pinned to the contract (T-102) so a server-side field
 * rename fails typecheck here rather than showing up as a blank line on screen.
 *
 * Two narrowings on top of the generated type: `type` keeps its
 * known-or-any-string union (the generated `string` loses the autocomplete),
 * and `data` is an indexable record — the schema types it as an open object,
 * but every param the center interpolates is read out of it by key.
 */
export type NotificationRow = Omit<Notification, 'type' | 'data'> & {
  type: KnownNotificationType | (string & {});
  data: Record<string, unknown>;
};

export type NotificationsPage = {
  data: NotificationRow[];
  meta: {
    unread_count: number;
    pagination: { next_cursor: string | null; prev_cursor: string | null; limit: number };
  };
};
