<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Reads the whole content of release $version of the GameSystem with $key owned by $ownerId, with the
 * card images it pinned (ADR 0053).
 *
 * @implements Query<GameSystemReleaseContentView>
 */
final readonly class GetGameSystemReleaseContent implements Query
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $version,
    ) {
    }
}
