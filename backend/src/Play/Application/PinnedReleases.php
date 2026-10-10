<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;

/**
 * Reads the release a campaign is pinned to, never the latest one (ADR 0014).
 */
final class PinnedReleases
{
    /**
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public static function of(PublishedGameSystemReleases $releases, Campaign $campaign): GameSystemSnapshot
    {
        $pinned = $campaign->pinnedRelease();

        return $releases->get($pinned->gameSystemKey(), $pinned->releaseVersion());
    }
}
