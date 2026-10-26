<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Deletes the point budget with this key from the sheet of the owner's GameSystem draft (ADR
 * 0043). $expectedVersion is the draft version the client read.
 */
final readonly class RemoveSheetPointBudgetEntry implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
        public string $budgetKey,
    ) {
    }
}
