<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Moves a check of the owner's GameSystem draft to position $index of the whole check list.
 * $expectedVersion is the draft version the client read.
 */
final readonly class MoveCheck implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
        public string $check,
        public int $index,
    ) {
    }
}
