<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks what upgrading one of the player's campaigns to the latest release of its GameSystem would change.
 *
 * @implements Query<ReleaseUpgradePreview>
 */
final readonly class PreviewReleaseUpgrade implements Query
{
    public function __construct(
        public string $campaignId,
        public string $userId,
    ) {
    }
}
