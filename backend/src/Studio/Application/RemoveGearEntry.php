<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Deletes the gear entry with this key from the catalog of the owner's GameSystem draft (ADR
 * 0045). $expectedVersion is the draft version the client read.
 */
final readonly class RemoveGearEntry implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
        public string $gearKey,
    ) {
    }
}
