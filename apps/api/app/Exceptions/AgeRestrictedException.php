<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The signup age gate refusing (T-113).
 *
 * A DISTINCT error code rather than a generic validation failure, and the
 * reason is localization. Nothing in this API calls `App::setLocale()`, so every
 * validation message it returns is English — and the app's default language is
 * Spanish. A "too young" refusal shown in English at the moment someone is
 * signing up is exactly the wrong place to be careless.
 *
 * So the server states the RULE and the client states it in the user's
 * language: `minimum_age` travels in the details, the app renders its own copy
 * around that number. That keeps the number in one place — `config('legal
 * .minimum_age')`, which the published terms also read — instead of mirroring
 * it into the mobile bundle where the two would silently drift the first time
 * it changed.
 */
class AgeRestrictedException extends Exception
{
    public function __construct(private readonly int $minimumAge)
    {
        parent::__construct("You need to be at least {$minimumAge} to use Reelmap.");
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'age_restricted';
    }

    /**
     * @return array{minimum_age: int, field: string}
     */
    public function details(): array
    {
        // `field` so the client can attach the message to the input it came
        // from, the way it would for an ordinary validation error.
        return ['minimum_age' => $this->minimumAge, 'field' => 'date_of_birth'];
    }
}
