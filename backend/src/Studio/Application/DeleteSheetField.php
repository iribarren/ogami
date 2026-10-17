<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Deletes a field of the sheet of the owner's GameSystem draft. $expectedVersion is the draft
 * version the client read.
 */
final readonly class DeleteSheetField implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
        public string $field,
    ) {
    }
}
