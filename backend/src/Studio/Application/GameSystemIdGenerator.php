<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\GameSystem\GameSystemId;

/**
 * Port: hands out a new, unique GameSystemId, for callers that generate it before dispatching
 * CreateGameSystem because commands return nothing.
 */
interface GameSystemIdGenerator
{
    public function generate(): GameSystemId;
}
