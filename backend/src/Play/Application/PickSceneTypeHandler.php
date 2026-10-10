<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\SceneTypeNotOffered;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnknownFlow;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class PickSceneTypeHandler implements CommandHandler
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
     * @throws FlowRunNotActive                when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws FlowRunPositionMismatch         when the FlowRun is not at the scene pick
     * @throws SceneTypeNotOffered             when the pick does not offer it
     * @throws NoCurrentSession                when no session is under way
     * @throws CampaignLimitReached            when the session already holds 200 scenes
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     * @throws UnknownFlow                     when the pinned release has no Flow with the campaign's key
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     */
    public function __invoke(PickSceneType $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $release = PinnedReleases::of($this->releases, $campaign);
        $campaign->pickSceneType($command->sceneTypeKey, $release, $this->clock->now());
        $this->campaigns->save($campaign);
    }
}
