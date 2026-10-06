<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for one of the player's campaigns.
 *
 * @implements Query<CampaignView>
 */
final readonly class GetCampaign implements Query
{
    public function __construct(
        public string $campaignId,
        public string $userId,
    ) {
    }
}
