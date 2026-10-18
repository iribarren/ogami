<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\Command;

/**
 * Stops a user from signing in; their data stays. Idempotent.
 */
final readonly class DisableUser implements Command
{
    public function __construct(
        public string $actorId,
        public string $userId,
    ) {
    }
}
