<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * An NPC or Thread a pick step picked, with its name as it was at pick time.
 */
final readonly class PickedItem
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
