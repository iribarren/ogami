<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;

/**
 * Port to the published GameSystem releases Play may play (docs/contracts/gamesystem-release.md).
 * Play never reads Studio drafts, only published releases (ADR 0010).
 */
interface PublishedGameSystemReleases
{
    /**
     * The given release version of a GameSystem, or its latest when $version is null.
     *
     * @throws GameSystemReleaseNotFound       when no published release matches
     * @throws UnsupportedReleaseSchemaVersion when the release follows a schema version Play cannot read
     * @throws InvalidGameSystemRelease        when a supported release still cannot be read
     */
    public function get(string $gameSystemKey, ?int $version = null): GameSystemSnapshot;
}
