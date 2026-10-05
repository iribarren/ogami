<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for a user's public data, or null when no such user exists.
 *
 * @implements Query<UserView|null>
 */
final readonly class GetUser implements Query
{
    public function __construct(
        public string $userId,
    ) {
    }
}
