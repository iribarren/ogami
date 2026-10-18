<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\Command;

/**
 * Replaces a user's roles. The acting user's id lets the handler protect their
 * own account.
 */
final readonly class ChangeUserRoles implements Command
{
    /**
     * @param list<string> $roles Role values, e.g. "SOLO_PLAYER"
     */
    public function __construct(
        public string $actorId,
        public string $userId,
        public array $roles,
    ) {
    }
}
