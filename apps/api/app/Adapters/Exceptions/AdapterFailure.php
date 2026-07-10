<?php

namespace App\Adapters\Exceptions;

/**
 * Common contract for adapter failures so the calling job (T-016) can catch and
 * map any of them to `shares.failure_code` without knowing the concrete class.
 */
interface AdapterFailure
{
    /** A stable code from the §8 failure taxonomy. */
    public function failureCode(): string;
}
