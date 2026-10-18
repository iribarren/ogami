<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use App\Identity\Application\PasswordResetIdGenerator;
use App\Identity\Domain\PasswordResetId;

/**
 * Test double that hands out "reset-1", "reset-2"… in order.
 */
final class SequentialPasswordResetIdGenerator implements PasswordResetIdGenerator
{
    private int $next = 1;

    public function generate(): PasswordResetId
    {
        return PasswordResetId::fromString(\sprintf('reset-%d', $this->next++));
    }
}
