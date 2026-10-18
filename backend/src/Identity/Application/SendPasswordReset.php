<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\Command;

/**
 * The owner sends a password reset link to a user. The caller picks the reset id (see PasswordResetIdGenerator).
 */
final readonly class SendPasswordReset implements Command
{
    public function __construct(
        public string $resetId,
        public string $userId,
    ) {
    }
}
