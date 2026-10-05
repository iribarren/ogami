<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\UserId;

/**
 * Port: hands out a new, unique UserId. Callers generate the id before
 * dispatching CreateUser, because commands return nothing.
 */
interface UserIdGenerator
{
    public function generate(): UserId;
}
