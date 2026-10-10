<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\FlowRunPositionMismatch;
use App\Play\Domain\Campaign\FlowRun\MoveOnNotAllowed;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnknownFlow;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class MoveOnHandler implements CommandHandler
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
     * @throws FlowRunPositionMismatch         when the current phase is another, or the guided scene is no longer the current scene
     * @throws MoveOnNotAllowed                when the phase plays once
     * @throws CampaignLimitReached            when the next scene does not fit the session
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     * @throws UnknownFlow                     when the pinned release has no Flow with the campaign's key
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     */
    public function __invoke(MoveOn $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $release = PinnedReleases::of($this->releases, $campaign);
        $campaign->moveOn($command->phaseKey, $release, $this->clock->now());
        $this->campaigns->save($campaign);
    }
}
