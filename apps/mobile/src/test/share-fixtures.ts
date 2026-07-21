import type { ReelmapExtraction } from '@reelmap/contracts';
import type { ShareDetail } from '@/api/shares';

/** A complete extraction fixture; `place` overrides merge onto places[0]. */
export function extraction(place: Partial<ReelmapExtraction['places'][number]> = {}): ReelmapExtraction {
  return {
    places: [
      {
        name: 'La Gran Burger',
        handle: 'lagranburgerok',
        confidence: 0.9,
        category: 'restaurant',
        cuisines: ['burgers'],
        address: { street: 'Av. Italia 123', city: 'Barros Blancos', region: 'Canelones', postal_code: null, country: 'UY' },
        geo: { lat: -34.76, lng: -55.9 },
        price_range: 2,
        phone: null,
        website: null,
        opening_hours_text: null,
        dishes: [{ name: 'Cheeseburger', shown_in_video: true, price: '$450' }],
        vibe_tags: ['casual'],
        dietary_tags: [],
        discounts: [],
        ...place,
      },
    ],
    influencer: { platform: 'instagram', handle: 'reviewer', display_name: 'Reviewer' },
    post: { language: 'es', caption_summary: null, is_sponsored_disclosure: false },
    evidence: { caption_quotes: ['best burger in town'], transcript_quotes: [], frame_refs: [0] },
    // city deliberately low-confidence to exercise the tint.
    confidence: { overall: 0.9, per_field: { 'places[0].name': 0.9, 'places[0].address.city': 0.4 } },
  };
}

export function shareDetail(over: Partial<ShareDetail> = {}): ShareDetail {
  return {
    id: '1',
    status: 'pending',
    status_history: [],
    source_post: {
      id: '1',
      platform: 'instagram',
      url: 'https://instagram.com/reel/x',
      author_handle: 'reviewer',
      caption: null,
      fetch_status: 'ok',
    },
    analysis: null,
    failure: null,
    can_publish_best_guess: false,
    place: null,
    places: [],
    pending_place_count: 0,
    pending_places: [],
    ...over,
  };
}

/** A share paused in `review` with a full, editable extraction. */
export function reviewShare(over: Partial<ShareDetail> = {}): ShareDetail {
  return shareDetail({
    status: 'review',
    analysis: { run_id: 'r1', model: 'gpt', status: 'succeeded', confidence: 0.9, extraction: extraction() },
    failure: { code: 'geocode_failed', step: 'resolve', message: 'Couldn’t place it', manual_fallback: true },
    ...over,
  });
}
