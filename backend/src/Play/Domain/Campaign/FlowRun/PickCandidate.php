<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * An open NPC or Thread a pick step can pick: its id, its name now and the weight (1 to 3) that
 * makes a random draw favour it.
 */
final readonly class PickCandidate
{
    public function __construct(
        public string $id,
        public string $name,
        public int $weight,
    ) {
    }
}
