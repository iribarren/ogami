<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * A line of the owner's GameSystem list. `libraryChanges` counts the draft's library imports that changed or
 * went away in their pack.
 */
final readonly class GameSystemSummary
{
    public function __construct(
        public string $key,
        public string $name,
        public int $schemaVersion,
        public ?int $latestReleaseVersion,
        public bool $hasUnpublishedChanges,
        public int $libraryChanges,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
