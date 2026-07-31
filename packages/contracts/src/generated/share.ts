/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/schemas/share.json
 */
export type ShareStatus = 'pending' | 'fetching' | 'analyzing' | 'review' | 'published' | 'failed' | 'rejected';

/**
 * GET /api/v1/shares/{id} (T-016, 03 §3.2) — the ingest pipeline's state for one shared post: status + derived history, the source post, the latest analysis run (extraction per the canonical extraction contract), the failure/review reason, and the places it published or parked.
 */
export interface ShareDetail {
  id: string;
  status: ShareStatus;
  /**
   * Derived checkpoints (share_stage_metrics + created_at), oldest first, ending at the current status.
   */
  status_history: {
    status: ShareStatus;
    at: string | null;
  }[];
  source_post: {
    id: string;
    platform: string;
    /**
     * The canonical post URL. Always present — `source_posts.url` is NOT NULL; a manual caption-only share still gets a synthetic one.
     */
    url: string;
    /**
     * The crediting influencer's handle, null when the post has no linked influencer.
     */
    author_handle: string | null;
    caption: string | null;
    fetch_status: string;
  };
  /**
   * The latest analysis run. Null until one starts.
   */
  analysis: {
    run_id: string;
    /**
     * The model id the run used. Always present — `analysis_runs.model` is NOT NULL, set before the run is created.
     */
    model: string;
    status: string;
    confidence: number | null;
    /**
     * The model's structured output — the canonical extraction contract. Null while a run is in flight, and on a run that failed before producing schema-valid output.
     */
    extraction: null | ReelmapExtraction;
  } | null;
  /**
   * Why the pipeline stopped or paused. Present for `failed` and for `review` (where `manual_fallback` is true and it explains what the user must resolve); null otherwise.
   */
  failure: {
    code: string;
    /**
     * The last pipeline stage that ran, null when nothing was recorded.
     */
    step: string | null;
    message: string;
    manual_fallback: boolean;
  } | null;
  /**
   * True when an uncertain review can be published as-is without being located first (T-098).
   */
  can_publish_best_guess: boolean;
  /**
   * The primary published pin — null until the share publishes. Back-compat single-place view of `places[0]`.
   */
  place: null | SharePlace;
  /**
   * Every published pin, primary first — a multi-place post fans out to several. Empty until the share publishes.
   */
  places: SharePlace[];
  /**
   * How many extracted venues are still parked for review (partial publish). Equals `pending_places.length`.
   */
  pending_place_count: number;
  /**
   * The parked venues themselves, each with the candidate places it can be attached to (T-071). Owner-only surface.
   */
  pending_places: PendingVenue[];
}
export interface ReelmapExtraction {
  /**
   * EVERY distinct venue reviewed in the post, one entry each. A single-venue post has exactly one entry; a roundup (e.g. "the 5 best cafés") has one entry per venue. NEVER merge two venues into one entry, and NEVER split one venue into several.
   */
  places: {
    /**
     * Restaurant/venue name exactly as stated in the source. null if not identifiable.
     */
    name: string | null;
    /**
     * The venue's OWN Instagram handle WITHOUT the leading @ (e.g. "lagranburgerok"), taken from an @mention in the caption/transcript that attributes THIS venue. NEVER the posting account (POSTED BY / reviewer). null when the venue is not given by an @handle.
     */
    handle: string | null;
    /**
     * Confidence 0-1 that THIS venue is correctly identified from the source. Drives per-place auto-publish vs. review.
     */
    confidence: number;
    category:
      'restaurant' | 'cafe' | 'bar' | 'bakery' | 'street_food' | 'food_truck' | 'dessert' | 'market' | 'other' | null;
    /**
     * Real cuisine / food-type labels ONLY (lowercase English), e.g. "thai", "neapolitan pizza", "bakery", "coffee". NEVER ambiance or quality descriptions. Empty if unstated.
     */
    cuisines: string[];
    address: {
      street: string | null;
      city: string | null;
      /**
       * State/province/prefecture.
       */
      region: string | null;
      postal_code: string | null;
      /**
       * ISO 3166-1 alpha-2 when confidently known, else full name, else null.
       */
      country: string | null;
    };
    /**
     * Only when explicit coordinates appear in the source (e.g. geotag text). Never inferred.
     */
    geo: {
      lat: number;
      lng: number;
    } | null;
    /**
     * 1=$ … 4=$$$$. null if unstated.
     */
    price_range: number | null;
    phone: string | null;
    website: string | null;
    /**
     * Verbatim hours text from the source, unparsed.
     */
    opening_hours_text: string | null;
    dishes: {
      name: string;
      /**
       * true only if the dish visibly appears in the keyframes/video.
       */
      shown_in_video: boolean;
      /**
       * The dish's menu price EXACTLY as written, including its currency symbol (e.g. "$450", "12€", "UYU 320"). null if no price is shown for this item.
       */
      price?: string | null;
    }[];
    /**
     * Ambiance/occasion — pick ONLY values from the fixed enum (map a stated quality to its closest match; OMIT anything that doesn't clearly fit). A caption praising the lighting, décor, or acoustics is NOT a tag.
     */
    vibe_tags: (
      | 'cozy'
      | 'romantic'
      | 'lively'
      | 'quiet'
      | 'casual'
      | 'upscale'
      | 'trendy'
      | 'minimalist'
      | 'rustic'
      | 'family friendly'
      | 'outdoor seating'
      | 'rooftop'
      | 'great view'
      | 'good for groups'
      | 'date night'
      | 'counter seating'
      | 'pet friendly'
      | 'live music'
      | 'brunch spot'
      | 'late night'
      | 'quick eats'
      | 'hidden gem'
      | 'spacious'
    )[];
    /**
     * ONLY values from the fixed enum, and only when stated or clearly shown.
     */
    dietary_tags: (
      | 'vegan'
      | 'vegan options'
      | 'vegetarian'
      | 'vegetarian options'
      | 'gluten-free'
      | 'dairy-free'
      | 'halal'
      | 'kosher'
      | 'organic'
      | 'plant-based'
    )[];
    /**
     * Card/bank/wallet payment discounts stated in the caption/transcript for THIS venue — e.g. "20% con Santander", "3 cuotas sin interés con Visa", "15% pagando con Mercado Pago". ONLY when a payment method is explicitly tied to a benefit; NEVER inferred and NEVER borrowed from another venue in a roundup. Empty when none stated.
     */
    discounts?: {
      /**
       * Card network / wallet if named: e.g. "Visa", "Mastercard", "Amex", "Diners", "Mercado Pago". null if only a bank/issuer is named.
       */
      scheme?: string | null;
      /**
       * Bank / card issuer name if named in PLAIN TEXT (e.g. "Santander", "Itaú", "BROU", "Prex"). null if the issuer is given ONLY by an @handle (put it in `handle` instead).
       */
      issuer?: string | null;
      /**
       * Instagram handle of the bank/issuer WITHOUT the leading @, when the discount is attributed via an @mention rather than a plain-text name (e.g. "santander.uy"). The issuer name is resolved from that profile downstream. null otherwise.
       */
      handle?: string | null;
      /**
       * The discount/benefit EXACTLY as stated, e.g. "20% off", "3 cuotas sin interés", "2x1".
       */
      terms: string;
      /**
       * The percentage discount if a % figure is stated, else null.
       */
      percent?: number | null;
    }[];
  }[];
  influencer: {
    platform: 'instagram' | 'x' | 'tiktok' | 'youtube' | null;
    handle: string | null;
    display_name: string | null;
  };
  post: {
    /**
     * BCP-47 primary language of the post content, e.g. "en", "pt-BR".
     */
    language: string | null;
    caption_summary: string | null;
    /**
     * true if #ad, #sponsored, paid-partnership label, or equivalent disclosure is present.
     */
    is_sponsored_disclosure: boolean;
  };
  evidence: {
    /**
     * Verbatim caption substrings supporting the extraction.
     */
    caption_quotes: string[];
    transcript_quotes: string[];
    /**
     * Indexes of supporting keyframes as provided in the prompt.
     */
    frame_refs: number[];
  };
  confidence: {
    overall: number;
    /**
     * Map of dotted field path (e.g. "places[0].name", "places[0].address.city") to confidence 0-1.
     */
    per_field: {
      [k: string]: number;
    };
  };
}
/**
 * A published pin: enough to drop/centre a marker without a second query.
 */
export interface SharePlace {
  id: string;
  name: string;
  lat: number;
  lng: number;
}
export interface PendingVenue {
  /**
   * The venue's position in the extraction's `places` array.
   */
  index: number;
  name: string | null;
  reason: string | null;
  candidates: {
    place_id: string;
    name: string | null;
    address: string | null;
    distance_m: number | null;
    similarity: number | null;
  }[];
}
