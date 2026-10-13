<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Characters\UnknownCharacter;
use App\Play\Domain\Campaign\InvalidSceneCast;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Shared\Application\Bus\CommandHandler;

final readonly class EditCurrentSceneCastHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws InvalidNpc                   when an NPC id is blank
     * @throws InvalidCharacter             when a Character id is blank
     * @throws UnknownNpc                   when an id is not an NPC of the campaign
     * @throws UnknownCharacter             when an id is not a Character of the campaign
     * @throws InvalidSceneCast             when an NPC or a Character is listed twice
     * @throws NoCurrentScene               when no session is under way or it has no scene
     * @throws CampaignModifiedConcurrently when another request saved the campaign meanwhile
     */
    public function __invoke(EditCurrentSceneCast $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $campaign->editCurrentSceneCast(
            array_map(NpcId::fromString(...), $command->npcIds),
            null === $command->characterIds ? null : array_map(CharacterId::fromString(...), $command->characterIds),
        );
        $this->campaigns->save($campaign);
    }
}
