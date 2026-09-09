<?php

namespace App\Exceptions;

use App\Exceptions\Contracts\ApiError;
use Exception;

/**
 * Thrown by login when the password is correct but the account's email has not
 * been confirmed (T-066). Carries its own 403 and `email_not_verified` code (see the ApiError contract),
 * plus the email so the client can route to the
 * verify screen prefilled. Fires only AFTER a valid password check, so it never
 * reveals account existence to someone who doesn't already hold the password.
 */
class EmailNotVerifiedException extends Exception implements ApiError
{
    public function __construct(private readonly string $email)
    {
        parent::__construct('Confirmá tu correo antes de iniciar sesión.');
    }

    public function status(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'email_not_verified';
    }

    /**
     * @return array<string, string>
     */
    public function details(): array
    {
        return ['email' => $this->email];
    }
}
