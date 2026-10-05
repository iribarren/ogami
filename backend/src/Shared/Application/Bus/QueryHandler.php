<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

/**
 * Marker for query handlers. Implementations expose
 * `__invoke(SomeQuery $query): SomeResult` and are registered on the query bus
 * automatically (see config/services.yaml).
 */
interface QueryHandler
{
}
