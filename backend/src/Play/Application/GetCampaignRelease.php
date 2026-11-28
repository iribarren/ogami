<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for the content of the release one of the player's campaigns is pinned to.
 *
 * @implements Query<ReleaseContentView>
 */
final readonly class GetCampaignRelease implements Query
{
    public function __construct(
        public string $userId,
        public string $campaignId,
    ) {
    }
}
