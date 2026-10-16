<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Shows the trackers, tags, fact slots, Scene Types and flows of the GameSystem draft with $key
 * owned by $ownerId, with the catalog its pickers need.
 *
 * @implements Query<GameSystemNarrativeView>
 */
final readonly class GetGameSystemNarrative implements Query
{
    public function __construct(
        public string $ownerId,
        public string $key,
    ) {
    }
}
