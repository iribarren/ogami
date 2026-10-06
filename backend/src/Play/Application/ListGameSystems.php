<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for the GameSystems a campaign can be created with: the latest release of each.
 *
 * @implements Query<list<GameSystemSummary>>
 */
final readonly class ListGameSystems implements Query
{
}
