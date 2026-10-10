<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\FlowRun\FlowRun;

/**
 * Campaigns in states that only later slices reach through commands.
 */
final class Campaigns
{
    /**
     * A campaign like the given one whose FlowRun was changed by the closure. Campaign::flowRun()
     * hands out a copy, so a seam of the FlowRun that no command reaches yet (an endPhase or
     * nextScene effect, play-flow-run slice 18) is set on a copy that a rebuilt campaign takes.
     *
     * @param \Closure(FlowRun): void $change
     * @param ?CampaignId             $id     the rebuilt campaign's id, the same by default
     */
    public static function withFlowRun(Campaign $campaign, \Closure $change, ?CampaignId $id = null): Campaign
    {
        $flowRun = $campaign->flowRun() ?? throw new \LogicException('The campaign has no FlowRun.');
        $change($flowRun);

        return Campaign::reconstitute($id ?? $campaign->id(), $campaign->ownerId(), $campaign->name(), $campaign->pinnedRelease(), $campaign->createdAt(), $campaign->sessions(), $campaign->trackerValues(), $campaign->flowKey(), $flowRun);
    }
}
