<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

use App\Shared\Application\Bus\Query;

/**
 * Asks whether the application and its dependencies are reachable.
 *
 * @implements Query<HealthReport>
 */
final readonly class CheckHealth implements Query
{
}
