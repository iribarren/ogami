<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;
use App\Studio\Domain\GameSystem\LibrarySection;

/**
 * Forgets that an item of the owner's GameSystem draft came from the library; the item stays.
 * $expectedVersion is the draft version the client read.
 */
final readonly class DetachLibraryImport implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
        public LibrarySection $section,
        public string $itemKey,
    ) {
    }
}
