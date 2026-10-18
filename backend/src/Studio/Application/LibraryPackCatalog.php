<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * What a game manager browses before importing from a pack: its metadata and its items by section.
 */
final readonly class LibraryPackCatalog
{
    /**
     * @param list<LibraryPackCatalogSection> $sections every library section, in order, even when empty
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $description,
        public bool $mine,
        public \DateTimeImmutable $updatedAt,
        public array $sections,
    ) {
    }
}
