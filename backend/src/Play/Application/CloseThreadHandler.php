<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Lists\InvalidThread;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\Lists\UnknownThread;
use App\Shared\Application\Bus\CommandHandler;

final readonly class CloseThreadHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws UnknownThread                when the campaign holds no Thread with this id
     * @throws InvalidThread                when the id is blank
     * @throws CampaignModifiedConcurrently when another request saved the campaign meanwhile
     */
    public function __invoke(CloseThread $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);

        $campaign->closeThread(ThreadId::fromString($command->threadId));
        $this->campaigns->save($campaign);
    }
}
