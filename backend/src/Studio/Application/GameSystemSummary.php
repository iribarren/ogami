<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * A line of the owner's GameSystem list.
 */
final readonly class GameSystemSummary
{
    public function __construct(
        public string $key,
        public string $name,
        public int $schemaVersion,
        public ?int $latestReleaseVersion,
        public bool $hasUnpublishedChanges,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
