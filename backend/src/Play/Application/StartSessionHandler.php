<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Shared\Application\Bus\CommandHandler;

final readonly class StartSessionHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private Clock $clock,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws CampaignLimitReached
     * @throws CampaignModifiedConcurrently when another request saved the campaign meanwhile
     */
    public function __invoke(StartSession $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $campaign->startSession($this->clock->now());
        $this->campaigns->save($campaign);
    }
}
