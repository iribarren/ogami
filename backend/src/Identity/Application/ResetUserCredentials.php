<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\Command;

/**
 * Sets the password and roles of the user with this email and enables the user.
 * For seeding known credentials (see the --reset-existing option of app:user:create);
 * there is no acting user, so the Admin rules about the actor's own account do not apply. It may
 * therefore drop OWNER from the last active owner, on purpose: it is an operator tool.
 */
final readonly class ResetUserCredentials implements Command
{
    /**
     * @param list<string> $roles Role values, e.g. "SOLO_PLAYER"
     */
    public function __construct(
        public string $email,
        public string $plainPassword,
        public array $roles,
    ) {
    }
}
