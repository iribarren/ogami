<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Shared\Application\Bus\CommandHandler;

final readonly class EndSessionHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private Clock $clock,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws NoCurrentSession             when no session is under way (none has started, or it has ended), or another than the named one is
     * @throws CampaignModifiedConcurrently when another request saved the campaign meanwhile
     */
    public function __invoke(EndSession $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $campaign->endSession($this->clock->now(), $command->sessionNumber);
        $this->campaigns->save($campaign);
    }
}
