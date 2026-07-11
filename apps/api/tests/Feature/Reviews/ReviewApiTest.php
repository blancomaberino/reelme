<?php

use App\Models\Place;
use App\Models\Review;
use App\Models\ReviewReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function reviewPlace(): Place
{
    return Place::factory()->active()->atPoint(51.5, -0.13)->create();
}

it('creates a review via POST and 409s a duplicate', function () {
    $place = reviewPlace();
    Sanctum::actingAs(User::factory()->create(['is_public' => true]));

    $res = $this->postJson("/api/v1/places/{$place->id}/reviews", [
        'rating' => 5,
        'body' => 'Incredible hand-pulled noodles.',
    ])->assertStatus(201);

    $res->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.is_own', true)
        ->assertJsonPath('meta.rating.app.count', 1);
    expect((float) $res->json('meta.rating.app.value'))->toBe(5.0);

    $this->postJson("/api/v1/places/{$place->id}/reviews", ['rating' => 3])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');
});

it('upserts via PUT (idempotent single review per user) and reaggregates', function () {
    $place = reviewPlace();
    $other = User::factory()->create();
    Review::factory()->create(['place_id' => $place->id, 'user_id' => $other->id, 'rating' => 1]);

    Sanctum::actingAs(User::factory()->create());

    $this->putJson("/api/v1/places/{$place->id}/reviews", ['rating' => 5])
        ->assertOk()
        ->assertJsonPath('meta.rating.app.count', 2);

    $updated = $this->putJson("/api/v1/places/{$place->id}/reviews", ['rating' => 3, 'body' => 'Revised.'])
        ->assertOk();
    expect((float) $updated->json('meta.rating.app.value'))->toBe(2.0) // (1+3)/2
        ->and($updated->json('meta.rating.app.count'))->toBe(2)
        ->and(Review::count())->toBe(2);
});

it('deletes only the caller’s own review', function () {
    $place = reviewPlace();
    $other = User::factory()->create();
    Review::factory()->create(['place_id' => $place->id, 'user_id' => $other->id, 'rating' => 4]);

    $me = User::factory()->create();
    Review::factory()->create(['place_id' => $place->id, 'user_id' => $me->id, 'rating' => 2]);

    Sanctum::actingAs($me);
    $this->deleteJson("/api/v1/places/{$place->id}/reviews")
        ->assertOk()
        ->assertJsonPath('meta.rating.app.count', 1);

    // Second delete: nothing left to remove.
    $this->deleteJson("/api/v1/places/{$place->id}/reviews")->assertStatus(404);
    expect(Review::where('user_id', $other->id)->exists())->toBeTrue();
});

it('requires auth for writes and validates rating bounds', function () {
    $place = reviewPlace();

    $this->postJson("/api/v1/places/{$place->id}/reviews", ['rating' => 5])->assertStatus(401);

    Sanctum::actingAs(User::factory()->create());
    $this->putJson("/api/v1/places/{$place->id}/reviews", ['rating' => 6])->assertStatus(422);
    $this->putJson("/api/v1/places/{$place->id}/reviews", ['rating' => 0])->assertStatus(422);
    $this->putJson("/api/v1/places/{$place->id}/reviews", [])->assertStatus(422);
});

it('blocks spam and profanity at the door', function () {
    $place = reviewPlace();
    Sanctum::actingAs(User::factory()->create());

    $this->putJson("/api/v1/places/{$place->id}/reviews", [
        'rating' => 5,
        'body' => 'Buy now http://a.com http://b.com http://c.com',
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    $this->putJson("/api/v1/places/{$place->id}/reviews", [
        'rating' => 5,
        'body' => 'This place beats any casino buffet.',
    ])->assertStatus(422);
});

it('lists visible reviews with public authors, newest first, paginated', function () {
    $place = reviewPlace();
    $public = User::factory()->create(['is_public' => true, 'username' => 'publicfan']);
    $private = User::factory()->create(['is_public' => false]);

    $first = Review::factory()->create(['place_id' => $place->id, 'user_id' => $public->id, 'rating' => 5, 'body' => 'Great!']);
    $second = Review::factory()->create(['place_id' => $place->id, 'user_id' => $private->id, 'rating' => 2]);

    $res = $this->getJson("/api/v1/places/{$place->id}/reviews")->assertOk();

    expect($res->json('data.0.id'))->toBe((string) $second->id) // newest first
        ->and($res->json('data.0.author'))->toBeNull()            // private author withheld
        ->and($res->json('data.1.author.username'))->toBe('publicfan')
        ->and($res->json('data.1.created_at'))->not->toBeNull();

    $page1 = $this->getJson("/api/v1/places/{$place->id}/reviews?limit=1")->assertOk();
    $cursor = $page1->json('meta.pagination.next_cursor');
    $page2 = $this->getJson("/api/v1/places/{$place->id}/reviews?limit=1&cursor=".urlencode($cursor))->assertOk();
    expect($page2->json('data.0.id'))->toBe((string) $first->id)
        ->and($page2->json('meta.pagination.next_cursor'))->toBeNull();
});

it('excludes hidden reviews from the list, the detail embed and the aggregate', function () {
    $place = reviewPlace();
    Review::factory()->create(['place_id' => $place->id, 'user_id' => User::factory(), 'rating' => 5]);
    $hidden = Review::factory()->create(['place_id' => $place->id, 'user_id' => User::factory(), 'rating' => 1]);
    $hidden->is_hidden = true;
    $hidden->save();

    $list = $this->getJson("/api/v1/places/{$place->id}/reviews")->assertOk();
    expect($list->json('data'))->toHaveCount(1);

    $detail = $this->getJson("/api/v1/places/{$place->id}?include=reviews")->assertOk();
    expect($detail->json('data.rating.app.count'))->toBe(1)
        ->and((float) $detail->json('data.rating.app.value'))->toBe(5.0)
        ->and($detail->json('data.reviews'))->toHaveCount(1);
});

it('reports a review once per user and no-ops on self-report', function () {
    $place = reviewPlace();
    $author = User::factory()->create();
    $review = Review::factory()->create(['place_id' => $place->id, 'user_id' => $author->id, 'rating' => 1]);

    $reporter = User::factory()->create();
    Sanctum::actingAs($reporter);

    $this->postJson("/api/v1/reviews/{$review->id}/report", ['reason' => 'spam'])->assertOk();
    $this->postJson("/api/v1/reviews/{$review->id}/report", ['reason' => 'offensive'])->assertOk();
    expect(ReviewReport::where('review_id', $review->id)->count())->toBe(1)
        ->and(ReviewReport::sole()->reason)->toBe('spam'); // first report wins

    $this->postJson("/api/v1/reviews/{$review->id}/report", ['reason' => 'bogus'])->assertStatus(422);

    Sanctum::actingAs($author);
    $this->postJson("/api/v1/reviews/{$review->id}/report", ['reason' => 'spam'])->assertOk();
    expect(ReviewReport::count())->toBe(1); // self-report ignored

    // Hidden reviews are unreportable (404 like any invisible resource).
    $review->is_hidden = true;
    $review->save();
    Sanctum::actingAs($reporter);
    $this->postJson("/api/v1/reviews/{$review->id}/report", ['reason' => 'spam'])->assertStatus(404);
});

it('404s review routes for merged places', function () {
    $survivor = Place::factory()->active()->atPoint(51.5, -0.13)->create();
    $merged = Place::factory()->atPoint(51.5, -0.13)->create([
        'status' => 'merged',
        'merged_into_place_id' => $survivor->id,
    ]);

    $this->getJson("/api/v1/places/{$merged->id}/reviews")->assertStatus(404);

    Sanctum::actingAs(User::factory()->create());
    $this->putJson("/api/v1/places/{$merged->id}/reviews", ['rating' => 4])->assertStatus(404);
});

it('keeps the aggregate consistent under interleaved writes', function () {
    $place = reviewPlace();
    $users = User::factory()->count(5)->create();

    foreach ($users as $i => $user) {
        Sanctum::actingAs($user);
        $this->putJson("/api/v1/places/{$place->id}/reviews", ['rating' => ($i % 5) + 1])->assertOk();
    }

    // The aggregate is computed from the table at read time — after any
    // interleaving, it must equal the table's truth exactly.
    $detail = $this->getJson("/api/v1/places/{$place->id}")->assertOk();
    expect($detail->json('data.rating.app.count'))->toBe(5)
        ->and((float) $detail->json('data.rating.app.value'))->toBe(3.0); // (1+2+3+4+5)/5
});
