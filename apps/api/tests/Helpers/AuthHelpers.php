<?php

declare(strict_types=1);

/**
 * A valid registration payload, in one place.
 *
 * Lives here rather than beside the tests that use it because more than one
 * suite registers accounts, and the signup contract is exactly the kind that
 * grows a REQUIRED field: T-113 added `date_of_birth`, and every caller that
 * built its own payload inline started returning 422 instead. One builder means
 * the next required field is a one-line change rather than a hunt.
 *
 * (It was briefly defined inside AuthTest.php. That works for a full-suite run,
 * where Pest loads every test file, and breaks the moment somebody runs another
 * file on its own to debug it — which is precisely when they need it to work.)
 */
function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Maya Diner',
        'username' => 'maya',
        'email' => 'maya@example.com',
        'password' => 'secret123!',
        'device_name' => 'cli',
        // Required since T-113. Checked by the API and discarded — the column
        // stays null, which RegisterAgeGateTest asserts.
        'date_of_birth' => '1990-01-01',
    ], $overrides);
}
