<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Lists\NpcId;

/**
 * Port: hands out a new, unique NpcId. Callers generate the id before dispatching AddNpc,
 * because commands return nothing, and then read the campaign for the NPC by that id.
 */
interface NpcIdGenerator
{
    public function generate(): NpcId;
}
