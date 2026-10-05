<?php

declare(strict_types=1);

namespace App\Tests\Support\Studio;

use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseRepository;
use App\Studio\Domain\Release\ReleaseId;

final class InMemoryGameSystemReleaseRepository implements GameSystemReleaseRepository
{
    /** @var array<string, GameSystemRelease> by id */
    private array $releases = [];

    public function add(GameSystemRelease $release): void
    {
        $this->releases[$release->id()->toString()] = $release;
    }

    public function latestFor(string $gameSystemKey): ?GameSystemRelease
    {
        $latest = null;
        foreach ($this->releases as $release) {
            if ($release->gameSystemKey() === $gameSystemKey && $release->version() > ($latest?->version() ?? 0)) {
                $latest = $release;
            }
        }

        return $latest;
    }

    public function get(string $gameSystemKey, int $version): ?GameSystemRelease
    {
        foreach ($this->releases as $release) {
            if ($release->gameSystemKey() === $gameSystemKey && $release->version() === $version) {
                return $release;
            }
        }

        return null;
    }

    public function ofId(ReleaseId $id): ?GameSystemRelease
    {
        return $this->releases[$id->toString()] ?? null;
    }

    public function count(): int
    {
        return \count($this->releases);
    }
}
