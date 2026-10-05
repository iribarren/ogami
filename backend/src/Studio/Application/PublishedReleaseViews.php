<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\Release\GameSystemRelease;

/**
 * Turns a GameSystem release into the PublishedReleaseView other contexts read, so the view
 * itself stays free of Studio Domain types.
 */
final class PublishedReleaseViews
{
    public static function of(GameSystemRelease $release): PublishedReleaseView
    {
        return new PublishedReleaseView(
            $release->id()->toString(),
            $release->gameSystemKey(),
            $release->version(),
            $release->schemaVersion(),
            $release->publishedAt(),
            $release->content()->toArray(),
        );
    }
}
