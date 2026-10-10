<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\HookSceneHasNoSceneType;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnknownSceneType;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class SwitchSceneTypeHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private PublishedGameSystemReleases $releases,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws UnknownSceneType                when the pinned release has no Scene Type with this key
     * @throws NoCurrentScene
     * @throws HookSceneHasNoSceneType         when the current scene is a hook Scene
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function __invoke(SwitchSceneType $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);

        // FlowRun history for hand switches comes with the FlowRun (play-flow-run T10).
        $campaign->switchSceneType(SceneTypes::ofPinnedRelease($this->releases, $campaign, $command->sceneType));
        $this->campaigns->save($campaign);
    }
}
