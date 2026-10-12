<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * The GameSystem release a campaign is pinned to; theme names the curated Theme the release asks
 * for, null for the default app theme.
 */
final readonly class PinnedReleaseView
{
    public function __construct(
        public string $gameSystemKey,
        public string $gameSystemName,
        public int $version,
        public ?string $theme,
    ) {
    }
}
