<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * The release history of the GameSystem with $key owned by $ownerId, the newest first.
 *
 * @implements Query<list<GameSystemReleaseSummary>>
 */
final readonly class ListGameSystemReleases implements Query
{
    public function __construct(
        public string $ownerId,
        public string $key,
    ) {
    }
}
