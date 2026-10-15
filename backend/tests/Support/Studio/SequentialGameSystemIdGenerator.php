<?php

declare(strict_types=1);

namespace App\Tests\Support\Studio;

use App\Studio\Application\GameSystemIdGenerator;
use App\Studio\Domain\GameSystem\GameSystemId;

/**
 * Hands out "gamesystem-1", "gamesystem-2"… so tests can name the drafts they expect.
 */
final class SequentialGameSystemIdGenerator implements GameSystemIdGenerator
{
    private int $next = 1;

    public function generate(): GameSystemId
    {
        return GameSystemId::fromString(\sprintf('gamesystem-%d', $this->next++));
    }
}
