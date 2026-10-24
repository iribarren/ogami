<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Removes the point budget of the sheet: Characters are created without one. $expectedVersion is
 * the draft version the client read.
 */
final readonly class RemoveSheetPointBudget implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
    ) {
    }
}
