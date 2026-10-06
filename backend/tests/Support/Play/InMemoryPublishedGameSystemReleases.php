<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Application\GameSystemSummary;
use App\Play\Application\PublishedGameSystemReleases;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;

/**
 * In-memory port double: holds snapshots by GameSystem key and release version, and the
 * description of each release (snapshots carry none).
 */
final class InMemoryPublishedGameSystemReleases implements PublishedGameSystemReleases
{
    /** @var array<string, array<int, GameSystemSnapshot>> */
    private array $snapshots = [];

    /** @var array<string, array<int, ?string>> */
    private array $descriptions = [];

    public function add(GameSystemSnapshot $snapshot, ?string $description = null): void
    {
        $this->snapshots[$snapshot->gameSystemKey()][$snapshot->releaseVersion()] = $snapshot;
        $this->descriptions[$snapshot->gameSystemKey()][$snapshot->releaseVersion()] = $description;
    }

    public function get(string $gameSystemKey, ?int $version = null): GameSystemSnapshot
    {
        $versions = $this->snapshots[$gameSystemKey] ?? [];
        $version ??= [] === $versions ? null : max(array_keys($versions));

        return null !== $version && isset($versions[$version])
            ? $versions[$version]
            : throw GameSystemReleaseNotFound::for($gameSystemKey, $version);
    }

    public function latest(): array
    {
        $summaries = [];
        foreach (array_keys($this->snapshots) as $key) {
            $latest = $this->get((string) $key);
            $version = $latest->releaseVersion();
            $summaries[] = new GameSystemSummary($latest->gameSystemKey(), $latest->name(), $this->descriptions[$key][$version] ?? null, $version);
        }

        usort($summaries, static fn (GameSystemSummary $a, GameSystemSummary $b): int => [$a->name, $a->gameSystemKey] <=> [$b->name, $b->gameSystemKey]);

        return $summaries;
    }
}
