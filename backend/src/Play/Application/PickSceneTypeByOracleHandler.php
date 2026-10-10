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
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandHandler;

final readonly class PickSceneTypeByOracleHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private PublishedGameSystemReleases $releases,
        private RandomNumberGenerator $random,
        private Clock $clock,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws FlowRunNotActive                when the campaign plays freely, guidance is paused or the Flow is complete
     * @throws FlowRunPositionMismatch         when the FlowRun is not at the scene pick
     * @throws SceneTypeNotOffered             when the pick does not roll on a table, or the entry rolled names no Scene Type
     * @throws NoCurrentSession                when no session is under way
     * @throws CampaignLimitReached            when the session already holds 200 scenes
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     * @throws UnknownFlow                     when the pinned release has no Flow with the campaign's key
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     */
    public function __invoke(PickSceneTypeByOracle $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $release = PinnedReleases::of($this->releases, $campaign);
        $pick = $campaign->activeFlowRunView($release)->pick ?? throw FlowRunPositionMismatch::at('the scene pick', 'no scene pick');
        $table = $pick->table ?? throw SceneTypeNotOffered::noRoll();
        $campaign->pickSceneTypeByOracle($release->resolveOracleTable($table, $this->random), $release, $this->clock->now());
        $this->campaigns->save($campaign);
    }
}
