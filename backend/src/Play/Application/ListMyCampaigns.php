<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks for a player's own campaigns, newest first.
 *
 * @implements Query<list<CampaignSummaryView>>
 */
final readonly class ListMyCampaigns implements Query
{
    public function __construct(
        public string $userId,
    ) {
    }
}
