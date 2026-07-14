<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by login when the password is correct but the account's email has not
 * been confirmed (T-066). Mapped by ApiExceptionRenderer to a 403 with code
 * `email_not_verified`, carrying the email so the client can route to the
 * verify screen prefilled. Fires only AFTER a valid password check, so it never
 * reveals account existence to someone who doesn't already hold the password.
 */
class EmailNotVerifiedException extends Exception
{
    public function __construct(private readonly string $email)
    {
        parent::__construct('Confirmá tu correo antes de iniciar sesión.');
    }

    /**
     * @return array<string, string>
     */
    public function details(): array
    {
        return ['email' => $this->email];
    }
}
