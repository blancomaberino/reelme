<?php

namespace App\Exceptions\Contracts;

/**
 * An exception that already knows what it means to a client (T-156).
 *
 * `ApiExceptionRenderer` used to name each of these classes in its `match`, and
 * `bootstrap/app.php` kept a SECOND list to decide what was worth reporting.
 * The two drifted, and the drift published a false privacy claim: a refused
 * under-age signup was filed as a server error, so a check that did not pass
 * left a durable record the policy said we never keep.
 *
 * Asking the renderer instead of keeping a parallel list fixed the consumer.
 * This fixes the producer. An exception is API-classified because it SAYS so,
 * not because someone remembered to add an arm — a seventh domain exception
 * written to this shape and forgotten would otherwise fall through to a 500,
 * which is the same bug wearing a new class name.
 *
 * `ExceptionContractTest` asserts that every exception in `App\Exceptions` with
 * a `status()` implements this, so "forgot to register it" cannot happen twice.
 */
interface ApiError
{
    /** The HTTP status this error renders as. */
    public function status(): int;

    /** The stable, machine-readable code in the error envelope. */
    public function errorCode(): string;

    /**
     * Structured context the client branches on.
     *
     * @return array<string, mixed>
     */
    public function details(): array;
}
