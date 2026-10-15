<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\SessionAlreadyUnderWay;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnknownFlow;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class StartSessionHandler implements CommandHandler
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
     * @throws SessionAlreadyUnderWay          when a session is under way
     * @throws CampaignLimitReached
     * @throws GameSystemReleaseNotFound       when the campaign plays a Flow and the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     * @throws UnknownFlow                     when the pinned release has no Flow with the campaign's key
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     */
    public function __invoke(StartSession $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        // Only a campaign along a Flow tells its FlowRun, which reads the pinned release.
        $release = null === $campaign->flowKey() ? null : PinnedReleases::of($this->releases, $campaign);
        $campaign->startSession($this->clock->now(), $release);
        $this->campaigns->save($campaign);
    }
}
