<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\GameSystem\LibrarySection;

/**
 * The items of one section of a pack, in document order.
 */
final readonly class LibraryPackCatalogSection
{
    /**
     * @param list<LibraryPackCatalogItem> $items
     */
    public function __construct(
        public LibrarySection $section,
        public array $items,
    ) {
    }
}
