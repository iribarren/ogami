<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;
use App\Studio\Domain\GameSystem\LibrarySection;

/**
 * Replaces an imported item of the owner's GameSystem draft with the library pack's current one.
 * $expectedVersion is the draft version the client read.
 */
final readonly class UpdateLibraryImport implements Command
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
