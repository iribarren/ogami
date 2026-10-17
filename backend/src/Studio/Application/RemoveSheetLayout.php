<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Removes the declared layout of the sheet, keeping each field's group. $expectedVersion is the
 * draft version the client read.
 */
final readonly class RemoveSheetLayout implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
    ) {
    }
}
