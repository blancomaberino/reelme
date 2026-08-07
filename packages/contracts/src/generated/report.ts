/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/report.json
 */
/**
 * A user's moderation flag against a piece of content (T-049, 03 §2.16, 02 §3.17). Returned to the REPORTER as a receipt — it deliberately carries nothing about triage (no resolver, no internal note, no count of other reports against the same target), because all of that would tell a malicious reporter how close they are to getting something removed.
 */
export interface Report {
  /**
   * Numeric id as a string, like every other id on the wire.
   */
  id: string;
  /**
   * The morph ALIAS of what was reported — never a class name. 03 §2.16 and 02 §3.17 list different sets (`offer` vs `source_post`); the union is what the API accepts.
   */
  reportable_type: 'place' | 'share' | 'user' | 'source_post' | 'offer';
  /**
   * Id of the reported record, as a string.
   */
  reportable_id: string;
  /**
   * Why it was flagged. Closed enum: the queue sorts and prioritises on this, so an open string would make triage impossible.
   */
  reason: 'spam' | 'wrong_place' | 'inappropriate' | 'copyright' | 'fraud' | 'other';
  /**
   * Whatever the reporter added in their own words. Null when they said nothing beyond the reason.
   */
  details: string | null;
  /**
   * Triage state. A reporter only ever sees `open` on the response to their own POST; the rest exist because the same shape is what the moderation tooling reads.
   */
  status: 'open' | 'reviewing' | 'resolved' | 'dismissed';
  /**
   * ISO-8601 timestamp.
   */
  created_at: string | null;
}
