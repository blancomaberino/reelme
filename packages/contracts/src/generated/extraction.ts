/**
 * GENERATED — do not edit; run `npm run generate` in packages/contracts.
 * Source of truth: packages/contracts/extraction.schema.json
 */
export interface ReelmapExtraction {
  place: {
    /**
     * Restaurant/venue name exactly as stated in the source. null if not identifiable.
     */
    name: string | null;
    category:
      'restaurant' | 'cafe' | 'bar' | 'bakery' | 'street_food' | 'food_truck' | 'dessert' | 'market' | 'other' | null;
    /**
     * Lowercase cuisine labels, e.g. "thai", "neapolitan pizza". Empty if unstated.
     *
     * @maxItems 32
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
    /**
     * @maxItems 32
     */
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
     * e.g. "cozy", "date night", "counter seating", "late night".
     *
     * @maxItems 32
     */
    vibe_tags: string[];
    /**
     * e.g. "vegan options", "halal", "gluten-free". Only when stated or clearly shown.
     *
     * @maxItems 32
     */
    dietary_tags: string[];
  };
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
     * Map of dotted field path (e.g. "place.name", "place.address.city") to confidence 0-1.
     */
    per_field: {
      [k: string]: number;
    };
  };
}
