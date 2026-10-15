<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\InvalidSceneTitle;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Shared\Application\Bus\CommandHandler;

final readonly class RenameCurrentSceneHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws NoCurrentScene               when no session is under way or it has no scene
     * @throws InvalidSceneTitle            when the trimmed title is blank or longer than 100 characters
     * @throws CampaignModifiedConcurrently when another request saved the campaign meanwhile
     */
    public function __invoke(RenameCurrentScene $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $campaign->renameCurrentScene($command->title);
        $this->campaigns->save($campaign);
    }
}
