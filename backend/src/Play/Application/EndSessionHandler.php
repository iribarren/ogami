<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\FlowRun\SessionCannotEndWhileGuided;
use App\Play\Domain\Campaign\NoCurrentSession;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnknownFlow;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class EndSessionHandler implements CommandHandler
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
     * @throws NoCurrentSession                when no session is under way (none has started, or it has ended), or another than the named one is
     * @throws SessionCannotEndWhileGuided     when guidance is active and the FlowRun is in a scene or a hook
     * @throws CampaignLimitReached            when the session closing's hook Scene does not fit the session
     * @throws GameSystemReleaseNotFound       when the campaign plays a Flow and the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     * @throws UnknownFlow                     when the pinned release has no Flow with the campaign's key
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     */
    public function __invoke(EndSession $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        // Only a campaign along a Flow tells its FlowRun, which reads the pinned release.
        $release = null === $campaign->flowKey() ? null : PinnedReleases::of($this->releases, $campaign);
        $campaign->endSession($this->clock->now(), $command->sessionNumber, $release);
        $this->campaigns->save($campaign);
    }
}
