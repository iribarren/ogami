<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\Command;

/**
 * Creates a user who can sign in. The caller picks the id (see UserIdGenerator)
 * so it knows the new user without the command returning a value.
 */
final readonly class CreateUser implements Command
{
    /**
     * @param list<string> $roles Role values, e.g. "SOLO_PLAYER"
     */
    public function __construct(
        public string $userId,
        public string $email,
        public string $plainPassword,
        public array $roles,
    ) {
    }
}
