<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Facts\FactId;

/**
 * Port: hands out a new, unique FactId. Callers generate the id before dispatching AddFact,
 * because commands return nothing, and then read the campaign for the fact by that id.
 */
interface FactIdGenerator
{
    public function generate(): FactId;
}
