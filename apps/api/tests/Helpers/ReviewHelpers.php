<?php

/*
|--------------------------------------------------------------------------
| Shared review-test helper
|--------------------------------------------------------------------------
| Used by the Reviews API and Reviews contract suites, which live in the same
| directory and run in the same Pest process — two same-bodied factories under
| two names was one definition too many. Loaded from Pest.php so it exists in
| every parallel worker regardless of which test files a process compiles.
*/

use App\Models\Place;

/** An active place with a real point, ready to hang reviews off. */
function reviewPlace(): Place
{
    return Place::factory()->active()->atPoint(51.5, -0.13)->create();
}
