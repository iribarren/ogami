<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Characters\CharacterAlreadyExists;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcAlreadyPromoted;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class PromoteNpcHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private PublishedGameSystemReleases $releases,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws UnknownNpc                      when the campaign holds no NPC with this id
     * @throws InvalidNpc                      when the NPC id is blank
     * @throws InvalidCharacter                when the Character id is blank
     * @throws NpcAlreadyPromoted              when the NPC was promoted already
     * @throws CampaignLimitReached            when the campaign already holds 100 Characters
     * @throws CharacterAlreadyExists          when the campaign holds a Character with this id
     * @throws UnknownReleaseTag               when a Tag of the NPC is not in the pinned release
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function __invoke(PromoteNpc $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        // The NPC is resolved before the release is read: an unknown NPC must not surface as a release failure.
        $npcId = NpcId::fromString($command->npcId);
        if (!$campaign->npc($npcId) instanceof Npc) {
            throw UnknownNpc::withId($npcId);
        }

        $pinned = $campaign->pinnedRelease();
        // The sheet comes from the pinned release, never the latest one (ADR 0014).
        $release = $this->releases->get($pinned->gameSystemKey(), $pinned->releaseVersion());
        $campaign->promoteNpc($npcId, CharacterId::fromString($command->characterId), $release);
        $this->campaigns->save($campaign);
    }
}
