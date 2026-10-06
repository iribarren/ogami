<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for the latest published release of each GameSystem, ordered by name then key: the release
 * catalog other contexts (Play) offer when a campaign is created.
 *
 * @implements Query<list<PublishedGameSystemSummary>>
 */
final readonly class ListPublishedGameSystems implements Query
{
}
