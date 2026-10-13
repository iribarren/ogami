<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Characters\UnknownCharacter;
use App\Play\Domain\Campaign\InvalidSessionParty;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Shared\Application\Bus\CommandHandler;

final readonly class EditCurrentSessionPartyHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws InvalidCharacter             when an id is blank
     * @throws UnknownCharacter             when an id is not a Character of the campaign
     * @throws InvalidSessionParty          when a Character is listed twice
     * @throws NoCurrentSession             when no session is under way
     * @throws CampaignModifiedConcurrently when another request saved the campaign meanwhile
     */
    public function __invoke(EditCurrentSessionParty $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $campaign->editCurrentSessionParty(array_map(CharacterId::fromString(...), $command->characterIds));
        $this->campaigns->save($campaign);
    }
}
