<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\SceneType;
use App\Play\Domain\GameSystem\UnknownSceneType;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;

/**
 * Looks up a Scene Type in the release a campaign is pinned to, never the latest one (ADR 0014).
 */
final class SceneTypes
{
    /**
     * @throws UnknownSceneType                when the pinned release has no Scene Type with this key
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public static function ofPinnedRelease(PublishedGameSystemReleases $releases, Campaign $campaign, string $key): SceneType
    {
        $pinned = $campaign->pinnedRelease();

        return $releases->get($pinned->gameSystemKey(), $pinned->releaseVersion())->sceneType($key) ?? throw UnknownSceneType::withKey($key);
    }
}
