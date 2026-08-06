/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/notification.json
 */
/**
 * One row of the notification center (T-040, 03 §2.15). `type` is the stable machine string from the server's `data.type` — never a PHP class name — and it is BOTH the routing/rendering discriminator and the translation key path the client uses (`share.published` → `notif.share.published.*`). The client renders its own copy from `type` + the interpolation params in `data`, so the center follows the in-app language toggle; `title`/`body` are the server's rendering of the same event, frozen in the language the recipient's account was set to when it was SENT, and are a fallback for a type the client does not know.
 */
export interface Notification {
  /**
   * UUID of the notification row.
   */
  id: string;
  /**
   * Stable machine string. The listed values are those the server emits today; the client MUST render an unknown-but-well-formed type generically (from `title`/`body`) rather than drop the row, which is what lets the server add a type before clients know it. Not an enum for that reason.
   */
  type: string;
  /**
   * Server-rendered title, in the recipient's language at send time. Null only for legacy rows written before this field existed — the client falls back to its own copy for the type, never to the raw `type` string.
   */
  title: string | null;
  /**
   * Server-rendered body, same caveats as `title`.
   */
  body: string | null;
  /**
   * In-app path, handed straight to `router.push`. MUST correspond to a real client route — a path with no route behind it dead-ends on the unmatched-route screen, and the push tap handler uses this same value.
   */
  url: string | null;
  /**
   * The full stored payload. Carries `type`/`url`/`title`/`body` plus the per-type interpolation params the client needs to render its own copy: `place_name` (share.published, redemption.verified), `follower_username` (social.follow), `influencer_handle` + `platform` (influencer.claim_rejected), `redemption_id`, `share_id`, `payout_id` + `amount_minor` + `currency` (wallet.payout). Open by design — a new key must never break an older client.
   */
  data: {};
  /**
   * ISO-8601 Zulu, or null while unread.
   */
  read_at: string | null;
  created_at: string | null;
}
