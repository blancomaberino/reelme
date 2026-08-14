<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\AgeRestrictedException;
use Carbon\CarbonImmutable;

/**
 * "Is this person old enough to be here?" (T-113)
 *
 * A separate class rather than a validation rule because there is a second
 * caller coming: social sign-in (T-067) creates accounts without ever touching
 * `RegisterRequest`, and an age gate that lives inside one request class is an
 * age gate the Apple/Google buttons walk straight past. That is the shape of
 * bug this codebase keeps finding in its seams — a rule enforced on one path
 * while a second path to the same outcome never learned about it.
 */
final class AgeCheck
{
    /**
     * @throws AgeRestrictedException when the date of birth is below the
     *                                configured minimum age
     */
    public static function enforce(string $dateOfBirth): void
    {
        $minimum = (int) config('legal.minimum_age');

        if (! self::isAtLeast($dateOfBirth, $minimum)) {
            throw new AgeRestrictedException($minimum);
        }
    }

    /**
     * Completed years, counted the way a person counts birthdays.
     *
     * Carbon rather than dividing days by 365: a year is not 365 days, and the
     * naive arithmetic is wrong for precisely the people a gate exists to
     * catch — someone born on 29 February, or exactly on the boundary date,
     * lands a day either side of eligible depending on how many leap years they
     * have lived through.
     */
    public static function isAtLeast(string $dateOfBirth, int $minimum): bool
    {
        $born = CarbonImmutable::parse($dateOfBirth)->startOfDay();

        return $born->diffInYears(CarbonImmutable::now()->startOfDay()) >= $minimum;
    }
}
