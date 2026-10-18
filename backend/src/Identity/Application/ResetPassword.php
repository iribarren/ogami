<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\Command;

/**
 * The user follows a reset link and chooses a new password.
 */
final readonly class ResetPassword implements Command
{
    public function __construct(
        #[\SensitiveParameter]
        public string $token,
        #[\SensitiveParameter]
        public string $password,
    ) {
    }
}
