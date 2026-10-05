<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Application\PublishedGameSystemReleases;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;

/**
 * In-memory port double: holds snapshots by GameSystem key and release version.
 */
final class InMemoryPublishedGameSystemReleases implements PublishedGameSystemReleases
{
    /** @var array<string, array<int, GameSystemSnapshot>> */
    private array $snapshots = [];

    public function add(GameSystemSnapshot $snapshot): void
    {
        $this->snapshots[$snapshot->gameSystemKey()][$snapshot->releaseVersion()] = $snapshot;
    }

    public function get(string $gameSystemKey, ?int $version = null): GameSystemSnapshot
    {
        $versions = $this->snapshots[$gameSystemKey] ?? [];
        $version ??= [] === $versions ? null : max(array_keys($versions));

        return null !== $version && isset($versions[$version])
            ? $versions[$version]
            : throw GameSystemReleaseNotFound::for($gameSystemKey, $version);
    }
}
