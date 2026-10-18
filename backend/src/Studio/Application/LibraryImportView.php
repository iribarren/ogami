<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\GameSystem\LibrarySection;

/**
 * An item the draft imported from the library.
 */
final readonly class LibraryImportView
{
    /**
     * @param array{key: string, name: string}|null $pack null when the pack is gone
     */
    public function __construct(
        public LibrarySection $section,
        public string $key,
        public string $label,
        public ?array $pack,
        public LibraryImportStatus $status,
        public bool $editedLocally,
    ) {
    }
}
