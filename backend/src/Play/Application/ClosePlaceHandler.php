<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Lists\InvalidPlace;
use App\Play\Domain\Campaign\Lists\PlaceId;
use App\Play\Domain\Campaign\Lists\UnknownPlace;
use App\Shared\Application\Bus\CommandHandler;

final readonly class ClosePlaceHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws UnknownPlace                 when the campaign holds no Place with this id
     * @throws InvalidPlace                 when the id is blank
     * @throws CampaignModifiedConcurrently when another request saved the campaign meanwhile
     */
    public function __invoke(ClosePlace $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);

        $campaign->closePlace(PlaceId::fromString($command->placeId));
        $this->campaigns->save($campaign);
    }
}
