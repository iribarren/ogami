<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Application\CampaignIdGenerator;
use App\Play\Domain\Campaign\CampaignId;

/**
 * Hands out "campaign-1", "campaign-2"… so tests can name the campaigns they expect.
 */
final class SequentialCampaignIdGenerator implements CampaignIdGenerator
{
    private int $next = 1;

    public function generate(): CampaignId
    {
        return CampaignId::fromString(\sprintf('campaign-%d', $this->next++));
    }
}
