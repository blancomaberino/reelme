import type {
  FeedItem as ContractFeedItem,
  InfluencerSummary as ContractInfluencerSummary,
  PlaceDetail as ContractPlaceDetail,
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
// Native reviews (T-128).
//
// Be honest about what this first line is: `AppReview` IS `ContractReview`
// today, so it reduces to `Exact<T, T>` and cannot currently fail. It is a
// tripwire, not a gate — it exists so that re-introducing a hand-written
// `AppReview` (which is how `updated_at` and `author.name` went missing in the
// first place) fails at the type level instead of quietly compiling.
const appReviewMatchesContract: Exact<AppReview, ContractReview> = true;
const placeReviewMatchesContract: Exact<
  NonNullable<PlaceDetail['reviews']>[number],
  ContractReview
> = true;

// PlaceDetail's OWN fields, one-directionally (T-128).
//
// `PlaceDetail` is a HAND-RESTATED mirror of place.json for ~30 fields, and
// nothing compared the two — which is the mechanism that let `opening_hours`
// sit wrong for months while every test was green. It cannot simply BE the
// contract type (it deliberately widens include-gated and older-cached fields
// like `can_edit`, `gallery` and `review_sources` to optional), so
// assignability, not `Exact<>`, is the enforceable relation.
//
// Scope, stated precisely rather than flatteringly: this guards the fields that
// are still restated. It does NOT guard `opening_hours` or `reviews` — those
// are DERIVED from the contract now (see api/places.ts), so both sides move
// together and this assert is trivially satisfied for them. Verified by
// mutation, both ways: reverting `opening_hours` to its old `["object",
// "array","null"]` union leaves tsc green (derivation, not this, is what holds
// it), while making a restated field nullable — `country_code` — fails with
// `TS2322: Type 'true' is not assignable to type 'never'`. Two mechanisms,
// different fields; neither one covers the whole object.
//
// It has already earned its place: adding it surfaced place.json's
// `google_reviews` items having no `required` and no `additionalProperties` —
// the same "admits anything" shape as the old `opening_hours` union, fixed here.
//
// The three `$ref` embeds are excluded because they are not this schema's to
// pin — `sources` is place-source.json, `offers` is offer.json, `my_tags` is
// inline. Each deserves its own pin against its own schema, and `sources`
// would currently FAIL one: place-source.json types `source_post` and its
// `url` as nullable, exactly as PlaceSourceResource emits them
// (`$post === null ? null : [...]`), while the mobile `PlaceSourceItem`
// declares both non-null — a real drift, and a latent crash on a place_source
// whose post was deleted. Filed, not fixed: it belongs to place-source.json.
type PlaceDetailOwnFields = Omit<PlaceDetail, 'sources' | 'offers' | 'my_tags'>;
type ContractOwnFields = Omit<ContractPlaceDetail, 'sources' | 'offers' | 'my_tags'>;
// `never` when the contract stops being assignable, so `= true` is the compile
// error — same mechanism as the Exact<> pins above, one direction instead of two.
type ContractSatisfiesPlaceDetail = ContractOwnFields extends PlaceDetailOwnFields ? true : never;
const placeDetailAcceptsContract: ContractSatisfiesPlaceDetail = true;

// The direction the assert above CANNOT see (found by review, T-128).
// `extends` permits extra properties on the contract side, so a contract field
// the app simply never restates passes it silently — which is a slower version
// of the same failure this file exists to stop: the app codes to its own idea
// of the payload and nobody compares. `claimed` is exactly that today.
//
// So the omissions are enumerated rather than left implicit. This is not a
// suppression list: it is a decision record that fails BOTH ways. A new
// contract field the app ignores widens the Exclude and breaks this line, so
// ignoring it becomes a choice someone writes down; and restating a field
// listed here narrows it to `never` and breaks the line too, so the note cannot
// outlive the omission it describes.
type ContractFieldsNotRestated = Exclude<keyof ContractOwnFields, keyof PlaceDetailOwnFields>;
/**
 * `claimed` — whether a verified operator holds this venue (T-041). The detail
 * screen branches on `can_edit` ("edit" vs "suggest a change"), which is about
 * THIS viewer; `claimed` is about the venue and no screen renders it. Left off
 * deliberately, not overlooked.
 */
type IntentionallyNotRestated = 'claimed';
const omissionsAreAccountedFor: Exact<ContractFieldsNotRestated, IntentionallyNotRestated> = true;

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
  expect(placeDetailAcceptsContract).toBe(true);
  expect(omissionsAreAccountedFor).toBe(true);
});

it('keeps realistic API payloads assignable to the contract types', () => {
  expect(placeFixture.country_code).toBe('GB');
  expect(shareFixture.status_history.at(-1)?.status).toBe('fetching');
  expect(feedFixture.sharer).toBeNull();
  expect(listFixture.items[0].place.slug).toBe('lanzhou-beef-noodle');
  expect(publicListFixture.owner).toBeNull();
  // `author.name` and `updated_at` are the two fields the hand-written AppReview
  // had silently dropped, so a fixture that carries them is the regression
  // marker. (The load-bearing check is the `satisfies ContractReview` on the
  // fixture itself — comparing two literals declared in this same file would
  // assert nothing.)
  expect(reviewFixture.author.name).toBe('Pia Fan');
});
