<?php

declare(strict_types=1);

namespace App\Tests\Support\Studio;

use App\Studio\Application\ReleaseIdGenerator;
use App\Studio\Domain\Release\ReleaseId;

/**
 * Hands out "release-1", "release-2"… so tests can name the releases they expect.
 */
final class SequentialReleaseIdGenerator implements ReleaseIdGenerator
{
    private int $next = 1;

    public function generate(): ReleaseId
    {
        return ReleaseId::fromString(\sprintf('release-%d', $this->next++));
    }
}
