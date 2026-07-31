<?php

/*
|--------------------------------------------------------------------------
| Shared contract-test helper
|--------------------------------------------------------------------------
| Used by the Shares / Feed / Lists / Places contract suites. Loaded from
| Pest.php so it exists in every parallel worker regardless of which test
| files a process happens to compile.
*/

use App\Support\Contracts\ApiSchema;

/**
 * Assert a live response payload validates against `packages/contracts/schemas/<schema>.json`,
 * putting the violations in the failure message so a drift says WHICH field moved.
 *
 * @param  object|array<mixed>  $payload
 */
function assertMatchesContract(object|array $payload, string $schema): void
{
    $errors = ApiSchema::errors(ApiSchema::validate($payload, $schema));

    expect($errors)->toBe([], "{$schema}.json violations: ".json_encode($errors));
}
