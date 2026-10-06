<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\InvalidSceneTitle;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Shared\Application\Bus\CommandHandler;

final readonly class StartSceneHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private Clock $clock,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws NoCurrentSession
     * @throws CampaignLimitReached
     * @throws InvalidSceneTitle
     */
    public function __invoke(StartScene $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $campaign->startScene($command->title, $this->clock->now());
        $this->campaigns->save($campaign);
    }
}
