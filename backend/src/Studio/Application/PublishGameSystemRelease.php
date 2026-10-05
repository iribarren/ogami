<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Validates a GameSystem release file and publishes it as the next immutable version of its
 * GameSystem (docs/contracts/gamesystem-release.md). With $onlyIfChanged, nothing is published
 * when the latest release of that GameSystem has the same content (idempotent preset seeding):
 * ask GetPublishedRelease afterwards and compare its releaseId with $releaseId to tell.
 */
final readonly class PublishGameSystemRelease implements Command
{
    /**
     * @param array<mixed> $content decoded release JSON, objects as string-keyed arrays
     */
    public function __construct(
        public string $releaseId,
        public array $content,
        public bool $onlyIfChanged,
    ) {
    }
}
