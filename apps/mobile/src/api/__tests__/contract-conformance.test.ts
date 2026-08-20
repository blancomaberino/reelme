import type {
  FeedItem as ContractFeedItem,
  InfluencerSummary as ContractInfluencerSummary,
  PlaceListDetail as ContractPlaceListDetail,
  PlaceListSummary as ContractPlaceListSummary,
  PlaceSummary as ContractPlaceSummary,
  Review as ContractReview,
  ShareDetail as ContractShareDetail,
  UserProfile,
  UserSummary,
} from '@reelmap/contracts';

import type { PlaceListDetail, PlaceListItem, PlaceListSummary, PublicPlaceList } from '../lists';
import type {
  AppReview,
  FeedItem,
  InfluencerSummary,
  PlaceDetail,
  PlaceSummary,
  SharerSummary,
} from '../places';
import type { PublicProfile } from '../profile';
import type { PendingCandidate, PendingVenue, ShareDetail, ShareFailure, SharePlace } from '../shares';

/**
 * Contract-drift guard (T-094, extended by T-102): every app type that mirrors an
 * API payload must stay identical to the generated @reelmap/contracts type. These
 * are COMPILE-TIME assertions — `npx tsc --noEmit` (the mobile gate + CI) fails if
 * a schema field is renamed, removed, or retyped and the generated TS diverges
 * from what the app codes to. The `expect`s below just make this a runnable Jest
 * file; the real check already happened at typecheck.
 */

// Bidirectional assignability = the two types are structurally identical.
type Exact<A, B> = [A] extends [B] ? ([B] extends [A] ? true : false) : false;

// Discovery (T-094).
const placeSummaryMatchesContract: Exact<PlaceSummary, ContractPlaceSummary> = true;
const publicProfileMatchesContract: Exact<PublicProfile, UserProfile> = true;
const sharerMatchesContract: Exact<SharerSummary, UserSummary | null> = true;
const influencerMatchesContract: Exact<InfluencerSummary, ContractInfluencerSummary> = true;

// Ingest + feed + lists (T-102).
const shareDetailMatchesContract: Exact<ShareDetail, ContractShareDetail> = true;
const feedItemMatchesContract: Exact<FeedItem, ContractFeedItem> = true;
const placeListMatchesContract: Exact<PlaceListSummary, ContractPlaceListSummary> = true;
const placeListDetailMatchesContract: Exact<PlaceListDetail, ContractPlaceListDetail> = true;

// Sub-shapes the screens destructure — pinned so a nested rename is caught where
// it's used, not just at the root.
const sharePlaceMatchesContract: Exact<SharePlace, ContractShareDetail['places'][number]> = true;
const shareFailureMatchesContract: Exact<ShareFailure, NonNullable<ContractShareDetail['failure']>> =
  true;
const pendingVenueMatchesContract: Exact<
  PendingVenue,
  ContractShareDetail['pending_places'][number]
> = true;
const pendingCandidateMatchesContract: Exact<
  PendingCandidate,
  ContractShareDetail['pending_places'][number]['candidates'][number]
> = true;
const listItemMatchesContract: Exact<PlaceListItem, ContractPlaceListDetail['items'][number]> = true;
// Native reviews (T-128). `AppReview` is now an alias of the contract, so the
// load-bearing pin is the second one: place detail's nested `reviews[]` must be
// the same Review the standalone endpoint returns, not a copy free to drift.
const appReviewMatchesContract: Exact<AppReview, ContractReview> = true;
const placeReviewMatchesContract: Exact<
  NonNullable<PlaceDetail['reviews']>[number],
  ContractReview
> = true;

// A realistic API row must satisfy the contract type verbatim — a renamed field
// (e.g. `country_code` → `country`) turns this into a tsc error, not a runtime one.
const placeFixture = {
  id: '1',
  name: 'Lanzhou Beef Noodle',
  slug: 'lanzhou-beef-noodle',
  status: 'active',
  lat: 51.5,
  lng: -0.13,
  category: 'ramen',
  price_range: 2,
  city: 'London',
  country_code: 'GB',
  thumbnail_url: null,
  mine: { share_id: '9', saved: false },
  source_count: 1,
  rating: { google: { value: 4.6, count: 80 } },
  distance_m: null,
  created_at: null,
} satisfies ContractPlaceSummary;

// A share mid-pipeline: the shape the status screen polls. Every key the API
// always emits must be present — `satisfies` rejects a missing or extra one.
const shareFixture = {
  id: '42',
  status: 'analyzing',
  status_history: [
    { status: 'pending', at: '2026-07-31T10:00:00Z' },
    { status: 'fetching', at: '2026-07-31T10:00:04Z' },
  ],
  source_post: {
    id: '7',
    platform: 'instagram',
    url: 'https://www.instagram.com/reel/ABC123/',
    author_handle: 'lisboneats',
    caption: 'best pastéis in town',
    fetch_status: 'ok',
  },
  analysis: null,
  failure: null,
  can_publish_best_guess: false,
  place: null,
  places: [],
  pending_place_count: 0,
  pending_places: [],
} satisfies ContractShareDetail;

// A published feed row with a private sharer — the nullable-attribution case.
const feedFixture = {
  id: '42',
  published_at: '2026-07-31T10:05:00Z',
  sharer: null,
  source_post: {
    platform: 'instagram',
    url: 'https://www.instagram.com/reel/ABC123/',
    caption: null,
    thumbnail_url: null,
  },
  influencer: null,
  place: placeFixture,
} satisfies ContractFeedItem;

const listFixture = {
  id: '3',
  name: 'Lisbon 2026',
  slug: 'lisbon-2026',
  public_slug: null,
  is_public: false,
  items_count: 1,
  items: [{ note: 'brunch', position: 1, place: placeFixture }],
  created_at: '2026-07-31T09:00:00Z',
  updated_at: '2026-07-31T09:00:00Z',
} satisfies ContractPlaceListDetail;

// Captured verbatim from ReviewResource rendered against a public author. This is
// the drift that shipped: the hand-written `AppReview` had silently lost
// `updated_at` and `author.name`, so nothing failed when the API sent them.
const reviewFixture = {
  id: '3',
  rating: 5,
  body: 'Great noodles, tiny queue.',
  author: { id: '7', username: 'publicfan', name: 'Pia Fan', avatar_path: 'avatars/7.jpg' },
  is_own: false,
  created_at: '2026-08-01T12:00:00Z',
  updated_at: '2026-08-02T09:30:00Z',
} satisfies ContractReview;

// The public read adds owner attribution on top of the same detail shape.
const publicListFixture: PublicPlaceList = { ...listFixture, owner: null };

it('pins the mobile API types to @reelmap/contracts', () => {
  expect(placeSummaryMatchesContract).toBe(true);
  expect(publicProfileMatchesContract).toBe(true);
  expect(sharerMatchesContract).toBe(true);
  expect(influencerMatchesContract).toBe(true);
  expect(shareDetailMatchesContract).toBe(true);
  expect(feedItemMatchesContract).toBe(true);
  expect(placeListMatchesContract).toBe(true);
  expect(placeListDetailMatchesContract).toBe(true);
  expect(sharePlaceMatchesContract).toBe(true);
  expect(shareFailureMatchesContract).toBe(true);
  expect(pendingVenueMatchesContract).toBe(true);
  expect(pendingCandidateMatchesContract).toBe(true);
  expect(listItemMatchesContract).toBe(true);
  expect(appReviewMatchesContract).toBe(true);
  expect(placeReviewMatchesContract).toBe(true);
});

it('keeps realistic API payloads assignable to the contract types', () => {
  expect(placeFixture.country_code).toBe('GB');
  expect(shareFixture.status_history.at(-1)?.status).toBe('fetching');
  expect(feedFixture.sharer).toBeNull();
  expect(listFixture.items[0].place.slug).toBe('lanzhou-beef-noodle');
  expect(publicListFixture.owner).toBeNull();
  expect(reviewFixture.author.name).toBe('Pia Fan');
  expect(reviewFixture.updated_at).not.toBe(reviewFixture.created_at);
});
