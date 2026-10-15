<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Shows release $version of the GameSystem with $key owned by $ownerId.
 *
 * @implements Query<GameSystemReleaseView>
 */
final readonly class GetGameSystemRelease implements Query
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $version,
    ) {
    }
}
