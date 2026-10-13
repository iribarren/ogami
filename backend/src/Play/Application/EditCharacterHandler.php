<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Characters\UnknownCharacter;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class EditCharacterHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private PublishedGameSystemReleases $releases,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws UnknownCharacter                when the campaign holds no Character with this id
     * @throws InvalidCharacter                when a value breaks a rule or does not fit the sheet
     * @throws UnknownReleaseTag               when a Tag is not in the pinned release
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function __invoke(EditCharacter $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        // The id is resolved before the release is read: an unknown Character must not surface as a release failure.
        $characterId = CharacterId::fromString($command->characterId);
        if (!$campaign->character($characterId) instanceof Character) {
            throw UnknownCharacter::withId($characterId);
        }

        $pinned = $campaign->pinnedRelease();
        $release = $this->releases->get($pinned->gameSystemKey(), $pinned->releaseVersion());

        $campaign->editCharacter($characterId, $command->name, $command->tags, $command->freeTags, $command->values, $release);
        $this->campaigns->save($campaign);
    }
}
