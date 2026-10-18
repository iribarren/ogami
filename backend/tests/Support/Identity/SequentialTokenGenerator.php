<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use App\Identity\Application\TokenGenerator;
use App\Identity\Domain\AccountToken;

/**
 * Test double that hands out "token-1", "token-2"… in order.
 */
final class SequentialTokenGenerator implements TokenGenerator
{
    private int $next = 1;

    public function generate(): AccountToken
    {
        return AccountToken::fromString(\sprintf('token-%d', $this->next++));
    }
}
