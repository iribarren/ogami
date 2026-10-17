<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Moves a field of the sheet of the owner's GameSystem draft to position $index of the whole field
 * list. $expectedVersion is the draft version the client read.
 */
final readonly class MoveSheetField implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
        public string $field,
        public int $index,
    ) {
    }
}
