<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Removes the currency from the sheet of the owner's GameSystem draft (ADR 0045).
 * $expectedVersion is the draft version the client read.
 */
final readonly class RemoveSheetCurrency implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
    ) {
    }
}
