import type { NotificationRow } from '@/api/notifications';
import { formatMoney } from '@/api/wallet';
import type { MessageKey } from '@/i18n';

/**
 * What a notification row SAYS, resolved on the client.
 *
 * The server also renders `title`/`body`, but it renders them once — in a queued
 * worker, in whatever language the account was set to at send time — and then
 * they are frozen in the database row forever. That is wrong for a list the user
 * scrolls next year with the language toggle flipped, and it was visibly wrong
 * in practice: the center listed English "New follower" rows above Spanish
 * pipeline rows inside a Spanish UI.
 *
 * So the center re-renders from `type` + the interpolation params the server
 * stores alongside it. `type` doubles as the key path (`share.published` →
 * `notif.share.published.*`), which is the same convention the API's lang files
 * use, so the two sides cannot drift apart silently.
 *
 * The fallback chain — translated → server-rendered → generic — exists because
 * each step covers a case the next cannot:
 *
 * 1. **Translated.** Every type this build knows, in the user's language now.
 * 2. **Server-rendered.** A type a NEWER server emits that this build has never
 *    heard of. Frozen language, but it is real copy about a real event.
 * 3. **Generic.** A row with neither — legacy rows written before the server
 *    stored `title`/`body` at all. The screen used to print `item.type` here,
 *    which is how twenty rows reading "share.published" ended up on screen.
 *    A machine string is never shown to a user.
 */

type Translate = (key: MessageKey, params?: Record<string, string | number>) => string;

export type NotificationCopy = { title: string; body: string | null };

/**
 * How each known type builds its copy.
 *
 * `body` returns a `[key, params]` pair rather than a finished string so the
 * table stays declarative and the plural/interpolation rules stay in `translate`.
 * Returning `null` means "this row has no usable params" — the caller then drops
 * to the server's copy, which is exactly right for a legacy row that predates
 * the params being stored.
 */
type Renderer = {
  title: MessageKey;
  body: (data: Record<string, unknown>) => [MessageKey, Record<string, string | number>?] | null;
};

/** A `data` value only if it is a non-empty string — legacy rows carry nulls. */
function str(data: Record<string, unknown>, key: string): string | null {
  const value = data[key];

  return typeof value === 'string' && value !== '' ? value : null;
}

const RENDERERS: Record<string, Renderer> = {
  'share.published': {
    title: 'notif.share.published.title',
    // The un-named variant is a separate string, not this one with an empty
    // placeholder: "ya está en tu mapa." reads as a truncated sentence, and the
    // two languages drop the subject differently.
    body: (data) => {
      const place = str(data, 'place_name');

      return place !== null
        ? ['notif.share.published.body', { place }]
        : ['notif.share.published.bodyFallback'];
    },
  },
  'share.review_needed': {
    title: 'notif.share.reviewNeeded.title',
    body: () => ['notif.share.reviewNeeded.body'],
  },
  'share.failed': {
    title: 'notif.share.failed.title',
    body: () => ['notif.share.failed.body'],
  },
  'social.follow': {
    title: 'notif.social.follow.title',
    body: (data) => {
      const username = str(data, 'follower_username');

      return username !== null ? ['notif.social.follow.body', { username }] : null;
    },
  },
  'influencer.claim_rejected': {
    title: 'notif.influencer.claimRejected.title',
    body: (data) => {
      const handle = str(data, 'influencer_handle');

      return handle !== null ? ['notif.influencer.claimRejected.body', { handle }] : null;
    },
  },
  'redemption.verified': {
    title: 'notif.redemption.verified.title',
    body: (data) => {
      const place = str(data, 'place_name');

      return place !== null
        ? ['notif.redemption.verified.body', { place }]
        : ['notif.redemption.verified.bodyFallback'];
    },
  },
  'wallet.payout': {
    title: 'notif.wallet.payout.title',
    body: (data) => {
      const amount = data.amount_minor;
      const currency = str(data, 'currency');

      // Formatted from minor units with the app's own money formatter, so a
      // payout reads the same here as it does on the wallet screen.
      return typeof amount === 'number' && currency !== null
        ? ['notif.wallet.payout.body', { amount: formatMoney({ amount, currency }) }]
        : null;
    },
  },
};

/**
 * The title and body to render for a row, in the user's current language.
 *
 * `title` is never empty and never a machine string — the whole point of the
 * generic last resort.
 */
export function notificationCopy(t: Translate, row: NotificationRow): NotificationCopy {
  const renderer = RENDERERS[row.type];

  if (renderer) {
    const body = renderer.body(row.data);

    return {
      title: t(renderer.title),
      // A known type with unusable params still gets its translated TITLE — it
      // is the server's body that is missing, not the whole row's meaning.
      body: body ? t(body[0], body[1]) : row.body,
    };
  }

  return {
    title: row.title ?? t('notif.unknown.title'),
    body: row.body,
  };
}
