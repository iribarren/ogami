<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Lists the GameSystem drafts owned by $ownerId, the most recently updated first.
 *
 * @implements Query<list<GameSystemSummary>>
 */
final readonly class ListMyGameSystems implements Query
{
    public function __construct(
        public string $ownerId,
    ) {
    }
}
