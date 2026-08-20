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
 * The include-set the place-detail contract is exercised with — DERIVED from
 * the endpoint's own allow-list, never a literal typed into this file.
 *
 * A contract test pinned to a hand-written `?include=sources,offers` is the
 * T-114/T-116 failure shape: a gate that cannot fail, because it validates a
 * combination no client sends and no fixture populates. Reading the allow-list
 * instead makes the tested set (a) always a superset of what any client can
 * successfully request — an unknown member is a 422 in PlaceShowRequest, never
 * a silent drop — and (b) self-extending: add an embed to the endpoint and this
 * test starts demanding a schema and a fixture for it on the next run.
 *
 * @return list<string>
 */
function contractDetailIncludes(): array
{
    return PlaceShowRequest::allowedIncludes();
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
    if (preg_match('/include:\s*[\'"]([^\'"]*)[\'"]/', $source, $m) !== 1) {
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
    $includes = contractDetailIncludes();
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
        expect(ApiSchema::errors(ApiSchema::validate($review, 'review')))->toBe([]);
    }
});

it('serves opening_hours as a flat list of strings when the place has hours', function () {
    $place = contractPlace();
    $place->update(['opening_hours_json' => [
        'Monday: 9:00 AM – 11:00 PM',
        'Tuesday: Closed',
    ]]);

    $data = $this->getJson("/api/v1/places/{$place->slug}")->assertOk()->json('data');

    // The JSON must be an ARRAY, not an object: a place whose hours were stored
    // associatively round-trips through jsonb as `{"monday": …}`, which still
    // decodes to a PHP array here — so assert on the encoded payload, which is
    // what the client actually parses.
    $encoded = json_encode($data['opening_hours']);
    expect($encoded)->toStartWith('[');
    expect($data['opening_hours'])->toBe([
        'Monday: 9:00 AM – 11:00 PM',
        'Tuesday: Closed',
    ]);
    foreach ($data['opening_hours'] as $line) {
        expect($line)->toBeString();
    }

    expect(ApiSchema::errors(ApiSchema::validate($data, 'place')))->toBe([]);
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

    $data = $this->getJson("/api/v1/places/{$place->slug}")->assertOk()->json('data');

    // Served as a LIST, so the client's `string[]` is not a lie. Asserted on the
    // encoded payload because an associative array still decodes to a PHP array
    // here — `toBe([...])` alone would pass on the object shape.
    expect(json_encode($data['opening_hours']))->toStartWith('[');
    // Salvaged rather than discarded: the VALUES are the lines a curator meant.
    expect($data['opening_hours'])->toBe(['9-5', 'Closed']);
    expect(ApiSchema::errors(ApiSchema::validate($data, 'place')))->toBe([]);
});

it('serves opening_hours as null when the place has none', function () {
    $place = contractPlace();
    expect($place->opening_hours_json)->toBeNull();

    $data = $this->getJson("/api/v1/places/{$place->slug}")->assertOk()->json('data');

    expect($data['opening_hours'])->toBeNull();
    expect(ApiSchema::errors(ApiSchema::validate($data, 'place')))->toBe([]);
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
    $hook = base_path('../mobile/src/api/hooks/usePlace.ts');
    if (! is_file($hook)) {
        $this->markTestSkipped("apps/mobile is not reachable from here ({$hook}); this guard runs in CI.");
    }

    $sent = mobilePlaceIncludes((string) file_get_contents($hook));
    expect($sent)->not->toBeEmpty();

    // Anything the screen sends must be an include the endpoint allows —
    // otherwise the app's own place detail 422s in production while every test
    // here stays green, which is exactly how T-116 shipped.
    $unknown = array_diff($sent, PlaceShowRequest::allowedIncludes());
    expect($unknown)->toBe([], 'usePlace.ts sends include(s) the API rejects: '.implode(', ', $unknown));

    // …and the payload the contract test above validates must cover them.
    expect(array_diff($sent, contractDetailIncludes()))->toBe([]);
});
