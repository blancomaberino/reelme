/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/place-edit-suggestion.json
 */
/**
 * A proposed correction to a place's business info (T-083): the response to POST /api/v1/places/{place}/suggestions, and one row of GET /api/v1/me/venues/suggestions. The submitter is deliberately absent — an operator judges the proposal, not the person who filed it.
 */
export interface PlaceEditSuggestion {
  id: string;
  place_id: string;
  /**
   * Present only where the row is listed across venues, so the screen can name the restaurant without a second request.
   */
  place?: {
    id: string;
    name: string;
    slug: string;
  };
  /**
   * A verified operator's own edit comes back `approved` — it applied on submit. Everyone else's is `pending` until a moderator decides.
   */
  status: 'pending' | 'approved' | 'rejected';
  /**
   * The row was the venue operator's own edit rather than a proposal awaiting review.
   */
  is_owner_submission: boolean;
  /**
   * One entry per changed field, in the allow-list's fixed order — a list rather than an object because JSON key order is not something a client may rely on, and both surfaces render these in order.
   */
  changes: {
    /**
     * Column name — the same vocabulary the submit endpoint accepts. The picture fields are absent by design: a suggestion may not propose an image URL.
     */
    field:
      | 'name'
      | 'address_line1'
      | 'address_line2'
      | 'city'
      | 'region'
      | 'postal_code'
      | 'country_code'
      | 'cuisine_primary'
      | 'price_range'
      | 'phone'
      | 'website'
      | 'opening_hours_json';
    /**
     * The value the submitter was looking at, captured at submit time. A reviewer compares it against the place as it is now to spot a proposal that has been overtaken.
     */
    from: string | number | unknown[] | null;
    to: string | number | unknown[] | null;
  }[];
  created_at: string;
  reviewed_at: string | null;
}
