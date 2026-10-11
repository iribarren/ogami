<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Lists\ThreadId;

/**
 * Port: hands out a new, unique ThreadId. Callers generate the id before dispatching AddThread,
 * because commands return nothing, and then read the campaign for the Thread by that id.
 */
interface ThreadIdGenerator
{
    public function generate(): ThreadId;
}
