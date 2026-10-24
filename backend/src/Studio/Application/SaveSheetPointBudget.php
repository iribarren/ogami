<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Replaces the point budget of the sheet of the owner's GameSystem draft (ADR 0040): the total a
 * Character spends over number fields at creation, with creation-only bounds. $expectedVersion is
 * the draft version the client read.
 */
final readonly class SaveSheetPointBudget implements Command
{
    /**
     * @param array<array-key, mixed> $budget the budget as in a release document ("label", "total", "fields", "min", "max"; the unset ones left out). Not typed further: a malformed value is refused with its path
     */
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
        public array $budget,
    ) {
    }
}
