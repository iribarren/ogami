<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Shared\Application\Bus\CommandHandler;

final readonly class PauseGuidanceHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private Clock $clock,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws FlowRunNotActive             when the campaign plays freely, guidance is already paused or the Flow is complete
     * @throws CampaignModifiedConcurrently when another request saved the campaign meanwhile
     */
    public function __invoke(PauseGuidance $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $campaign->pauseGuidance($this->clock->now());
        $this->campaigns->save($campaign);
    }
}
