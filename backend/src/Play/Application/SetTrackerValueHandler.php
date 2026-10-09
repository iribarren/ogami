<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\UnknownCampaignTracker;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class SetTrackerValueHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private PublishedGameSystemReleases $releases,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws UnknownCampaignTracker          when the pinned release has no Tracker with this key
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function __invoke(SetTrackerValue $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $pinned = $campaign->pinnedRelease();
        // The Tracker's range comes from the pinned release, never the latest one (ADR 0014).
        $tracker = $this->releases->get($pinned->gameSystemKey(), $pinned->releaseVersion())->tracker($command->trackerKey)
            ?? throw UnknownCampaignTracker::withKey($command->trackerKey);

        // FlowRun history for hand edits comes with the FlowRun (play-flow-run T10).
        $campaign->setTrackerValue($tracker, $command->value);
        $this->campaigns->save($campaign);
    }
}
