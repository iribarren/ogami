<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * A line of the library: a pack of any owner, with whether it is the reader's own.
 */
final readonly class LibraryPackSummary
{
    /**
     * @param array<string, int> $itemCounts by section: oracleTables, likelihoodOracles, trackers, tags, factSlots, sceneTypes
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $description,
        public bool $mine,
        public array $itemCounts,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
