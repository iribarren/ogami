<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Shows the checks of the GameSystem draft with $key owned by $ownerId: the checks as stored, what
 * their formulas may read and where each is used.
 *
 * @implements Query<GameSystemChecksView>
 */
final readonly class GetGameSystemChecks implements Query
{
    public function __construct(
        public string $ownerId,
        public string $key,
    ) {
    }
}
