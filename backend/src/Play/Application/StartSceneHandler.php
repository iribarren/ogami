<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\FlowRun\SceneCannotStartWhileGuided;
use App\Play\Domain\Campaign\InvalidSceneTitle;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnknownSceneType;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class StartSceneHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private PublishedGameSystemReleases $releases,
        private Clock $clock,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws UnknownSceneType                when the pinned release has no Scene Type with this key
     * @throws SceneCannotStartWhileGuided     when the Flow guides the campaign
     * @throws NoCurrentSession
     * @throws CampaignLimitReached
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     * @throws InvalidSceneTitle               also when there is neither a title nor a Scene Type
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function __invoke(StartScene $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $sceneType = null === $command->sceneType ? null : SceneTypes::ofPinnedRelease($this->releases, $campaign, $command->sceneType);

        $campaign->startScene($command->title, $this->clock->now(), $sceneType);
        $this->campaigns->save($campaign);
    }
}
