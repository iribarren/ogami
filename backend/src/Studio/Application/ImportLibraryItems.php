<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Copies items of the library pack $pack, and the pack items they need, into the owner's GameSystem
 * draft (ADR 0033). $items is the untrusted request list of {section, key}. $expectedVersion is
 * the draft version the client read.
 */
final readonly class ImportLibraryItems implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
        public string $pack,
        public mixed $items,
    ) {
    }
}
