<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Shared\Application\Bus\CommandHandler;

final readonly class CloseNpcHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws UnknownNpc                   when the campaign holds no NPC with this id
     * @throws InvalidNpc                   when the id is blank
     * @throws CampaignModifiedConcurrently when another request saved the campaign meanwhile
     */
    public function __invoke(CloseNpc $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);

        $campaign->closeNpc(NpcId::fromString($command->npcId));
        $this->campaigns->save($campaign);
    }
}
