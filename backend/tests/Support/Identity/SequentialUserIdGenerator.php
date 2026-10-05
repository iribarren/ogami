<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use App\Identity\Application\UserIdGenerator;
use App\Identity\Domain\UserId;

/**
 * Test double that hands out "user-1", "user-2"… in order.
 */
final class SequentialUserIdGenerator implements UserIdGenerator
{
    private int $next = 1;

    public function generate(): UserId
    {
        return UserId::fromString(\sprintf('user-%d', $this->next++));
    }
}
