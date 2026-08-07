<?php

use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\User;

/**
 * A user who has verifiably claimed `$place` — i.e. staff who may honour a code
 * at that counter.
 *
 * Lives here rather than in a test file because a function declared inside a
 * Pest test file is global only once THAT file is loaded: it resolves in a full
 * run and is undefined the moment somebody runs the other suite on its own.
 */
function operatorOfPlace(Place $place): User
{
    $operator = User::factory()->create();
    PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $operator->id]);

    return $operator;
}
