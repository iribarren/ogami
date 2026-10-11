<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\NpcAlreadyExists;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class AddNpcHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private PublishedGameSystemReleases $releases,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws InvalidNpc                      when a value breaks a rule, or the id is blank
     * @throws UnknownReleaseTag               when a Tag is not in the pinned release
     * @throws NpcAlreadyExists                when the campaign holds an NPC with this id
     * @throws CampaignLimitReached            when the campaign already holds 500 NPCs
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function __invoke(AddNpc $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $pinned = $campaign->pinnedRelease();
        // The Tags come from the pinned release, never the latest one (ADR 0014).
        $release = $this->releases->get($pinned->gameSystemKey(), $pinned->releaseVersion());

        $campaign->addNpc(NpcId::fromString($command->npcId), $command->name, $command->agenda, $command->tags, $command->freeTags, $command->weight, $release);
        $this->campaigns->save($campaign);
    }
}
