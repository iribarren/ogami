<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * The Outcome of a table step when the rolled entry has this key.
 */
final readonly class TableBranch
{
    public function __construct(
        public string $entry,
        public Outcome $outcome,
    ) {
    }
}
