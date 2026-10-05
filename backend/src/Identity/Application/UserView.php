<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * A user's public data: never the password hash.
 */
final readonly class UserView
{
    /**
     * @param list<string> $roles Role values, e.g. "SOLO_PLAYER"
     */
    public function __construct(
        public string $id,
        public string $email,
        public array $roles,
    ) {
    }
}
