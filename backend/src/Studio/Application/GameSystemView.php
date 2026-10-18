<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\GameSystem\GameSystemKind;

/**
 * The owner's view of a GameSystem draft or library pack: its metadata and what its sections hold. The draft may
 * be invalid, so the counts are read defensively. `libraryChanges` counts its library imports that changed or
 * went away in their pack.
 */
final readonly class GameSystemView
{
    /**
     * @param array<mixed>|null  $credits
     * @param array<string, int> $sectionCounts by section name: oracleTables, likelihoodOracles, trackers, factSlots, tags, sceneTypes, flows, sheetFields, checks (schema version 1: oracles, flowSteps)
     */
    public function __construct(
        public string $key,
        public GameSystemKind $kind,
        public string $name,
        public ?string $description,
        public ?array $credits,
        public ?string $theme,
        public int $schemaVersion,
        public array $sectionCounts,
        public ?int $latestReleaseVersion,
        public bool $hasUnpublishedChanges,
        public int $libraryChanges,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
