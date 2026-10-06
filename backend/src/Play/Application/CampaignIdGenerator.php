<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignId;

/**
 * Port: hands out a new, unique CampaignId. Callers generate the id before dispatching
 * CreateCampaign, because commands return nothing, and then read the campaign by that id.
 */
interface CampaignIdGenerator
{
    public function generate(): CampaignId;
}
