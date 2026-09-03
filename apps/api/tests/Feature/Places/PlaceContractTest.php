<?php

use App\Http\Requests\PlaceShowRequest;
use App\Models\Influencer;
use App\Models\Offer;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Review;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use App\Support\Contracts\ApiSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Model::preventLazyLoading();
});

afterEach(function () {
    Model::preventLazyLoading(false);
});

/**
 * Contract tests (T-030): live endpoint output must validate against the
 * canonical JSON Schemas in packages/contracts/schemas — the same files the
 * mobile app's TS types are generated from.
 */
function contractPlace(): Place
{
    $place = Place::factory()->active()->atPoint(38.7169, -9.1355)->create([
        'cuisine_primary' => 'chinese',
        'price_range' => 2,
        'google_rating' => 4.5,
        'google_rating_count' => 120,
        'shares_count' => 1,
    ]);

    $influencer = Influencer::factory()->create();
    $post = SourcePost::factory()->create(['influencer_id' => $influencer->id, 'posted_at' => now()]);
    $share = Share::factory()->create([
        'source_post_id' => $post->id,
        'user_id' => User::factory()->create(['is_public' => true])->id,
    ]);
    PlaceSource::factory()->create([
        'place_id' => $place->id,
        'source_post_id' => $post->id,
        'share_id' => $share->id,
        'extraction_snapshot_json' => [
            'cuisines' => ['chinese'],
            'dishes' => [['name' => 'Noodles', 'shown_in_video' => true]],
            'discounts' => [['issuer' => 'Santander', 'terms' => '20% off', 'percent' => 20]],
        ],
        'is_primary' => true,
    ]);

    // Every include-gated embed is POPULATED, not merely present (T-128). An
    // empty `reviews: []` / `offers: []` validates against any item schema at
    // all, so a fixture that leaves them empty pins nothing about the item
    // shape — the payload could be describing a field that no longer exists and
    // the gate would still be green.
    Review::factory()->create([
        'place_id' => $place->id,
        'user_id' => User::factory()->create(['is_public' => true])->id,
        'rating' => 5,
        'body' => 'Best noodles in Lisbon.',
    ]);
    // …and one by a PRIVATE reviewer, so `author: null` — the other branch of
    // review.json's `anyOf` — is exercised rather than assumed. A schema that
    // only ever sees a populated author cannot catch a resource that stopped
    // withholding private identities.
    Review::factory()->create([
        'place_id' => $place->id,
        'user_id' => User::factory()->create(['is_public' => false])->id,
        'rating' => 3,
        'body' => null,
    ]);

    Offer::factory()->active()->create([
        'place_id' => $place->id,
        'created_by_user_id' => User::factory()->create()->id,
    ]);

    return $place;
}

/**
 * The include-set the mobile place screen sends, parsed out of the hook that
 * sends it (apps/mobile/src/api/hooks/usePlace.ts).
 *
 * Throws rather than returning null when the literal cannot be found: a hook
 * that has been refactored past this regex must fail loudly here, because the
 * silent alternative is this guard quietly checking nothing.
 *
 * @return list<string>
 */
function mobilePlaceIncludes(string $source): array
{
    // Anchored on the `params: { … }` object, not on a bare `include:`. The
    // unanchored form took the FIRST match anywhere in the file, so a comment or
    // a docblock that happened to mention `include: 'x'` above the real call
    // would win it — and this guard would go on cheerfully checking a string the
    // hook never sends, which is precisely the silent-pass it exists to prevent.
    if (preg_match('/params:\s*\{[^}]*include:\s*[\'"]([^\'"]*)[\'"]/', $source, $m) !== 1) {
        throw new RuntimeException(
            'Could not find the `include:` literal in usePlace.ts. If the hook now builds its '
            .'include-set another way, update mobilePlaceIncludes() to read it from there — do '
            .'not delete this guard.'
        );
    }

    return array_values(array_filter(array_map('trim', explode(',', $m[1]))));
}

it('index rows validate against place-summary.json', function () {
    contractPlace();

    $rows = $this->getJson('/api/v1/places?near=38.7169,-9.1355')->assertOk()->json('data');
    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        $result = ApiSchema::validate($row, 'place-summary');
        expect(ApiSchema::errors($result))->toBe([]);
    }
});

it('place detail validates against place.json with every supported include', function () {
    $place = contractPlace();
    // DERIVED from the endpoint's own allow-list, never a literal typed here: a
    // contract test pinned to a hand-written `?include=sources,offers` is the
    // T-114/T-116 shape — a gate that cannot fail, because it validates a
    // combination no client sends and no fixture populates. Reading the
    // allow-list makes this self-extending instead: add an embed and this test
    // starts demanding a schema and a fixture for it on the next run.
    $includes = PlaceShowRequest::allowedIncludes();
    expect($includes)->not->toBeEmpty();

    $data = $this->getJson("/api/v1/places/{$place->slug}?include=".implode(',', $includes))
        ->assertOk()->json('data');

    // Guard the guard: each embed must actually carry rows. Without this the
    // schema check below passes on `[]` and proves nothing about the items.
    foreach ($includes as $include) {
        expect($data)->toHaveKey($include);
        expect($data[$include])->not->toBeEmpty("`{$include}` embed is empty — the fixture no longer populates it, so its item schema is untested.");
    }

    $result = ApiSchema::validate($data, 'place');
    expect(ApiSchema::errors($result))->toBe([]);
});

it('exercises both author branches of review.json in the reviews embed', function () {
    $place = contractPlace();

    $reviews = $this->getJson("/api/v1/places/{$place->slug}?include=reviews")
        ->assertOk()->json('data.reviews');

    $authors = array_map(fn (array $r) => $r['author'], $reviews);
    expect($authors)->toContain(null);
    expect(array_filter($authors))->not->toBeEmpty();

    // Each row on its own, so a failure names the review rather than the place.
    foreach ($reviews as $review) {
        assertMatchesContract($review, 'review');
    }
});

it('serves opening_hours as a flat list of strings when the place has hours', function () {
    $place = contractPlace();
    $place->update(['opening_hours_json' => [
        'Monday: 9:00 AM – 11:00 PM',
        'Tuesday: Closed',
    ]]);

    $response = $this->getJson("/api/v1/places/{$place->slug}")->assertOk();
    $data = $response->json('data');

    // Asserted on the RAW RESPONSE BODY — the bytes the client parses. Neither
    // `toBe([...])` nor a re-encode of the decoded value can catch an object
    // here: PHP decodes BOTH `["a","b"]` and `{"0":"a","1":"b"}` to the same
    // list, so re-encoding either one starts with '['. Only the wire format
    // distinguishes them.
    expect($response->getContent())->toContain('"opening_hours":[');
    expect($data['opening_hours'])->toBe([
        'Monday: 9:00 AM – 11:00 PM',
        'Tuesday: Closed',
    ]);
    foreach ($data['opening_hours'] as $line) {
        expect($line)->toBeString();
    }

    assertMatchesContract($data, 'place');
});

it('serves a legacy object-shaped opening_hours as a list, not an object', function () {
    $place = contractPlace();

    // Written past the model cast, the way a legacy row got there: validation on
    // the way IN is not enough on its own. `SuggestPlaceEditRequest` accepted a
    // bare `array` until T-128 and `PlaceEditSuggestion::patch()` filters
    // proposed changes by field NAME only, so a suggestion queued before that
    // fix still applies an associative array the moment a moderator approves it.
    DB::table('places')->where('id', $place->id)->update([
        'opening_hours_json' => json_encode(['monday' => '9-5', 'tuesday' => 'Closed']),
    ]);
    expect(json_decode(DB::table('places')->where('id', $place->id)->value('opening_hours_json'), true))
        ->toBe(['monday' => '9-5', 'tuesday' => 'Closed']);

    $response = $this->getJson("/api/v1/places/{$place->slug}")->assertOk();
    $data = $response->json('data');

    // Served as a LIST, so the client's `string[]` is not a lie. Asserted on the
    // RAW BODY: an associative array decodes to a PHP array here, so any
    // assertion made after `json()` has already lost the distinction.
    expect($response->getContent())->toContain('"opening_hours":[');
    // Salvaged rather than discarded — and the KEY rides along. A bare "9-5" on a
    // place detail reads as "open 9-5 every day", so dropping the day is worse
    // than useless; `OpeningHours::salvage()` renders it as "monday: 9-5".
    expect($data['opening_hours'])->toBe(['monday: 9-5', 'tuesday: Closed']);
    assertMatchesContract($data, 'place');
});

it('serves opening_hours as null when the place has none', function () {
    $place = contractPlace();
    expect($place->opening_hours_json)->toBeNull();

    $response = $this->getJson("/api/v1/places/{$place->slug}")->assertOk();
    $data = $response->json('data');

    // null, never `[]`: the client omits the row on null, but renders an empty
    // hours block — a heading with nothing under it — on an empty list.
    expect($response->getContent())->toContain('"opening_hours":null');
    expect($data['opening_hours'])->toBeNull();
    assertMatchesContract($data, 'place');
});

it('serves a legacy google_reviews row as the six keys place.json pins', function () {
    $place = contractPlace();

    // Written PAST THE MODEL CAST, the way a row from an earlier version of
    // `GooglePlacesGeocoder::reviews()` — or a hand edit in Filament/tinker —
    // got there. The contract test only ever sees rows the CURRENT writer
    // produces, so without this the schema's `additionalProperties: false` and
    // its six `required` keys are pinned against a payload nothing can violate.
    DB::table('places')->where('id', $place->id)->update([
        'google_reviews_json' => json_encode([
            // Missing `relative_time`, `time` and `profile_photo_url`; carries a
            // `language` key the schema forbids; `rating` arrived as a string.
            ['author' => 'Jane D.', 'text' => 'Incredible.', 'rating' => '5', 'language' => 'en'],
            'not even an object',
        ]),
        'google_reviews_synced_at' => now(),
    ]);

    $data = $this->getJson("/api/v1/places/{$place->slug}")->assertOk()->json('data');

    // The contract first: that is the thing the read boundary defends, and it is
    // what goes red the moment the column is served raw again.
    assertMatchesContract($data, 'place');
    expect($data['google_reviews'])->toBe([[
        'author' => 'Jane D.',
        'rating' => null, // '5' is a string, and the schema says number|null
        'text' => 'Incredible.',
        'relative_time' => null,
        'time' => null,
        'profile_photo_url' => null,
    ]]);
});

/**
 * `$count` cached Google review rows written PAST the model cast, the way a row
 * from an earlier version of `GooglePlacesGeocoder::reviews()` — or a hand edit
 * in Filament/tinker — got there. Shared by the two cap tests below, because
 * `google_reviews` and `review_sources[].snippets` read THE SAME column, and a
 * fixture that drifts between them is how the second one went uncapped.
 *
 * `google_place_id` and `google_rating` are set so the Google review SOURCE
 * resolves too — `GoogleReviewSource::summary()` returns null without them, and
 * the snippets assertion would then pass against an absent row.
 */
function seedLegacyGoogleReviews(Place $place, int $count): void
{
    DB::table('places')->where('id', $place->id)->update([
        'google_place_id' => 'ChIJcap0000000000000000000',
        'google_rating' => 4.5,
        'google_reviews_json' => json_encode(array_map(fn (int $i): array => [
            'author' => "Reviewer {$i}",
            'rating' => 5,
            'text' => "Review number {$i}.",
            'relative_time' => 'a week ago',
            'time' => 1700000000 + $i,
            'profile_photo_url' => null,
        ], range(1, $count))),
        'google_reviews_synced_at' => now(),
    ]);
}

it('caps google_reviews at the 5 place.json allows, however many the column holds', function () {
    $place = contractPlace();

    // Six rows written PAST the model cast — a column from before
    // `GooglePlacesGeocoder::reviews()` sliced to 5, or a hand edit. The WRITER
    // caps; that is the current writer, and this resource's whole job is to
    // distrust what is already in the column. Without a cap here the schema's
    // `maxItems: 5` (added reviewing T-128) would be violated by a LIVE
    // response — the tightening would have moved the bug rather than fixed it.
    seedLegacyGoogleReviews($place, 6);

    $data = $this->getJson("/api/v1/places/{$place->slug}")->assertOk()->json('data');

    assertMatchesContract($data, 'place');
    expect($data['google_reviews'])->toHaveCount(5);
    // The FIRST five, not an arbitrary five — Google orders them by relevance.
    expect(array_column($data['google_reviews'], 'author'))
        ->toBe(['Reviewer 1', 'Reviewer 2', 'Reviewer 3', 'Reviewer 4', 'Reviewer 5']);
});

it('caps review_sources snippets too — the same column served through a second door', function () {
    $place = contractPlace();

    // `google_reviews` and `review_sources[].snippets` both read
    // `google_reviews_json`. Reviewing T-128 capped the first and left the
    // second serving every row, so a six-row legacy column stayed fully exposed
    // through a door nobody had shut. Six rows, both doors, one assertion each.
    seedLegacyGoogleReviews($place, 6);

    $data = $this->getJson("/api/v1/places/{$place->slug}")->assertOk()->json('data');

    assertMatchesContract($data, 'place');

    $google = collect($data['review_sources'])->firstWhere('source', 'google');
    expect($google)->not->toBeNull('the google review source did not resolve — the fixture no longer exercises this path')
        ->and($google['snippets'])->toHaveCount(5)
        ->and(array_column($google['snippets'], 'author'))
        ->toBe(['Reviewer 1', 'Reviewer 2', 'Reviewer 3', 'Reviewer 4', 'Reviewer 5']);

    // And the sibling door stays shut.
    expect($data['google_reviews'])->toHaveCount(5);
});

it('sources rows validate against place-source.json', function () {
    $place = contractPlace();

    $rows = $this->getJson("/api/v1/places/{$place->slug}/sources")->assertOk()->json('data');
    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        $result = ApiSchema::validate($row, 'place-source');
        expect(ApiSchema::errors($result))->toBe([]);
    }
});

it('reads the mobile include-set out of the hook, and refuses a hook it cannot read', function () {
    expect(mobilePlaceIncludes("params: { include: 'sources,reviews,offers' },"))
        ->toBe(['sources', 'reviews', 'offers']);
    expect(mobilePlaceIncludes('params: { include: "sources" },'))->toBe(['sources']);

    // A DECOY must not win. Prose above the real call that spells `include: '…'`
    // is the cheapest way for this guard to start checking the wrong string, and
    // nothing downstream would notice: the assertions below would simply pass
    // against a set the app does not send.
    expect(mobilePlaceIncludes(
        "// historical note: this used to send include: 'everything,and,more'\n"
        ."  params: { include: 'sources' },"
    ))->toBe(['sources']);

    // The failure mode that matters: the literal moved, and the guard below must
    // shout instead of silently passing on an include-set it never found.
    expect(fn () => mobilePlaceIncludes('params: { include: buildIncludes() },'))
        ->toThrow(RuntimeException::class);
});

it('accepts every include the mobile place screen sends', function () {
    // apps/mobile is NOT mounted into the API's Sail container (compose.yaml
    // mounts apps/api and packages/contracts only), so this cross-app check can
    // only run where the whole monorepo is on disk — which is CI, the gate that
    // decides a merge (.github/workflows/ci.yml checks out the repo and runs the
    // API job straight on the runner). Locally it skips, loudly, rather than
    // pretending to have checked.
    // The skip is gated on the mobile TREE, not on the hook file. `is_file($hook)`
    // is false in exactly two situations — the Sail container, and the hook having
    // been RENAMED — and the second is the one change this guard exists to catch.
    // Skipping on it retired the guard in CI on the very PR that moved its target,
    // and `composer test` is bare `pest` with no `--fail-on-skipped`, so a skip is
    // indistinguishable from a pass in the log that decides the merge.
    $tree = base_path('../mobile/src');
    $hook = base_path('../mobile/src/api/hooks/usePlace.ts');
    if (! is_dir($tree)) {
        $this->markTestSkipped("apps/mobile is not reachable from here ({$tree}); this guard runs in CI.");
    }

    expect(is_file($hook))->toBeTrue(
        "apps/mobile is on disk but {$hook} is not — the place hook moved. Update BOTH the "
        .'path in tests/Feature/Places/PlaceContractTest.php and the hardcoded literal in '
        .'.github/workflows/ci.yml, which reads the same path and degrades the same way.'
    );

    $sent = mobilePlaceIncludes((string) file_get_contents($hook));
    expect($sent)->not->toBeEmpty();

    // Anything the screen sends must be an include the endpoint allows —
    // otherwise the app's own place detail 422s in production while every test
    // here stays green, which is exactly how T-116 shipped.
    $unknown = array_diff($sent, PlaceShowRequest::allowedIncludes());
    expect($unknown)->toBe([], 'usePlace.ts sends include(s) the API rejects: '.implode(', ', $unknown));

    // The other direction — every allowed include is EXERCISED by a populated
    // fixture — is asserted where the payload exists, in the
    // `place detail validates against place.json with every supported include` test.
});
