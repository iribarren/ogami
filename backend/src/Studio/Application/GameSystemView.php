<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * The owner's view of a GameSystem draft: its metadata and what its sections hold. The draft may
 * be invalid, so the counts are read defensively.
 */
final readonly class GameSystemView
{
    /**
     * @param array<mixed>|null  $credits
     * @param array<string, int> $sectionCounts by section name: oracleTables, likelihoodOracles, trackers, factSlots, tags, sceneTypes, flows, sheetFields, checks (schema version 1: oracles, flowSteps)
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $description,
        public ?array $credits,
        public ?string $theme,
        public int $schemaVersion,
        public array $sectionCounts,
        public ?int $latestReleaseVersion,
        public bool $hasUnpublishedChanges,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
