<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\GameSystem\LibraryItemRef;

/**
 * One item of a pack: its key, its label, and the other pack items it names directly.
 */
final readonly class LibraryPackCatalogItem
{
    /**
     * @param 'table'|'likelihood'|null $kind      what an oracle is; null for the other sections
     * @param list<LibraryItemRef>      $dependsOn the items of the pack this one names, not their closure
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $kind,
        public array $dependsOn,
    ) {
    }
}
