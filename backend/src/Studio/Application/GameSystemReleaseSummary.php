<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\Release\GameSystemRelease;

/**
 * One line of a GameSystem's release history. The content hash is the full sha256; a UI shortens it.
 */
final readonly class GameSystemReleaseSummary
{
    public function __construct(
        public int $version,
        public int $schemaVersion,
        public \DateTimeImmutable $publishedAt,
        public ?string $notes,
        public string $contentHash,
    ) {
    }

    public static function of(GameSystemRelease $release): self
    {
        return new self($release->version(), $release->schemaVersion(), $release->publishedAt(), $release->notes(), $release->contentHash());
    }
}
