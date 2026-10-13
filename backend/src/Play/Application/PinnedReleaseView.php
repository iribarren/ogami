<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\Credits;

/**
 * The GameSystem release a campaign is pinned to; theme names the curated Theme the release asks
 * for, null for the default app theme; credits are the notice, license and link the release carries, null without.
 */
final readonly class PinnedReleaseView
{
    public function __construct(
        public string $gameSystemKey,
        public string $gameSystemName,
        public int $version,
        public ?string $theme,
        public ?Credits $credits = null,
    ) {
    }
}
