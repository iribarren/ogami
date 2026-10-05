<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release;

/**
 * Port: where published GameSystem releases are kept. Releases are only ever added, never changed.
 */
interface GameSystemReleaseRepository
{
    /**
     * @throws GameSystemReleaseAlreadyExists when that GameSystem key and version is already kept
     */
    public function add(GameSystemRelease $release): void;

    /**
     * The release with the highest version of this GameSystem key, if any.
     */
    public function latestFor(string $gameSystemKey): ?GameSystemRelease;

    public function get(string $gameSystemKey, int $version): ?GameSystemRelease;

    public function ofId(ReleaseId $id): ?GameSystemRelease;
}
