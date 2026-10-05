<?php

declare(strict_types=1);

namespace App\Tests\Support\Studio;

use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseAlreadyExists;
use App\Studio\Domain\Release\GameSystemReleaseRepository;
use App\Studio\Domain\Release\ReleaseId;

final class InMemoryGameSystemReleaseRepository implements GameSystemReleaseRepository
{
    /** @var array<string, GameSystemRelease> by id */
    private array $releases = [];

    /**
     * @throws GameSystemReleaseAlreadyExists on a duplicate (gameSystemKey, version), like the database's unique index
     * @throws \LogicException                on a duplicate id, like the primary key
     */
    public function add(GameSystemRelease $release): void
    {
        if (isset($this->releases[$release->id()->toString()])) {
            throw new \LogicException(\sprintf('A release with id "%s" already exists.', $release->id()->toString()));
        }

        if ($this->get($release->gameSystemKey(), $release->version()) instanceof GameSystemRelease) {
            throw GameSystemReleaseAlreadyExists::for($release->gameSystemKey(), $release->version());
        }

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
