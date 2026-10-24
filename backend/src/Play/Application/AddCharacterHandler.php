<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Characters\CharacterAlreadyExists;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\CreationMethod;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandHandler;

final readonly class AddCharacterHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private PublishedGameSystemReleases $releases,
        private RandomNumberGenerator $random,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws InvalidCharacter                when a value breaks a rule or does not fit the sheet, the method does not fit it or is unknown, or the id is blank
     * @throws UnknownReleaseTag               when a Tag is not in the pinned release
     * @throws CharacterAlreadyExists          when the campaign holds a Character with this id
     * @throws CampaignLimitReached            when the campaign already holds 100 Characters
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function __invoke(AddCharacter $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $pinned = $campaign->pinnedRelease();
        // The Tags and the sheet come from the pinned release, never the latest one (ADR 0014).
        $release = $this->releases->get($pinned->gameSystemKey(), $pinned->releaseVersion());

        $method = null === $command->method ? null : (CreationMethod::tryFrom($command->method) ?? throw InvalidCharacter::because('The creation method is "roll" or "points".'));
        $campaign->addCharacter(CharacterId::fromString($command->characterId), $command->name, $command->tags, $command->freeTags, $command->values, $release, $method, $this->random);
        $this->campaigns->save($campaign);
    }
}
