<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Shows the oracle tables and likelihood oracles of the GameSystem draft with $key owned by $ownerId.
 *
 * @implements Query<GameSystemOraclesView>
 */
final readonly class GetGameSystemOracles implements Query
{
    public function __construct(
        public string $ownerId,
        public string $key,
    ) {
    }
}
