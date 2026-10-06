<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * The GameSystem release a campaign is pinned to.
 */
final readonly class PinnedReleaseView
{
    public function __construct(
        public string $gameSystemKey,
        public string $gameSystemName,
        public int $version,
    ) {
    }
}
