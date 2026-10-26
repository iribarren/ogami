<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Puts the point budget with this key on the sheet of the owner's GameSystem draft (ADR 0043):
 * replaces it in place, else appends it. A legacy single budget is the key "points".
 * $expectedVersion is the draft version the client read.
 */
final readonly class SaveSheetPointBudgetEntry implements Command
{
    /**
     * @param array<array-key, mixed> $budget the budget as in a release list ("label", "total", "fields", "min", "max"; the unset ones left out), without its key. Not typed further: a malformed value is refused with its path
     */
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
        public string $budgetKey,
        public array $budget,
    ) {
    }
}
