<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\Command;

/**
 * Someone asks, with their email, to choose a new password (no sign-in needed). The caller
 * picks the reset id (see PasswordResetIdGenerator). Nothing tells whether the email has an account.
 */
final readonly class RequestPasswordReset implements Command
{
    public function __construct(
        public string $resetId,
        public string $email,
    ) {
    }
}
