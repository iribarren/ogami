<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for every user, ordered by email.
 *
 * @implements Query<list<UserView>>
 */
final readonly class ListUsers implements Query
{
}
