<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\ReleaseContent;

/**
 * Turns a GameSystem release into the PublishedReleaseView other contexts read, so the view
 * itself stays free of Studio Domain types.
 *
 * Parsing the stored content validates it, which is the costly part: callers that already hold the
 * parsed content pass it in, so a release is parsed once.
 */
final class PublishedReleaseViews
{
    public static function of(GameSystemRelease $release, ?ReleaseContent $content = null): PublishedReleaseView
    {
        return new PublishedReleaseView(
            $release->id()->toString(),
            $release->gameSystemKey(),
            $release->version(),
            $release->schemaVersion(),
            $release->publishedAt(),
            ($content ?? $release->content())->toArray(),
        );
    }
}
