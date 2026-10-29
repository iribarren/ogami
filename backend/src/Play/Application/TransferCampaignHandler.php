<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignAlreadyExists;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Characters\CharacterAlreadyExists;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Characters\UnknownCharacter;
use App\Play\Domain\Campaign\InvalidCampaignId;
use App\Play\Domain\Campaign\InvalidCampaignName;
use App\Play\Domain\Campaign\Transfer\TransferFromOutdatedRelease;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnknownFlow;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class TransferCampaignHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private PublishedGameSystemReleases $releases,
        private Clock $clock,
    ) {
    }

    /**
     * The source is read, never saved. The new campaign pins the latest release of the source's
     * GameSystem, which the source must play already (ADR 0046).
     *
     * @throws CampaignNotFound                when the source does not exist or belongs to someone else
     * @throws InvalidCampaignId
     * @throws CampaignAlreadyExists           when the new id is already taken (checked first, whatever the repository does)
     * @throws GameSystemReleaseNotFound       when the source's GameSystem has no published release
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     * @throws TransferFromOutdatedRelease     when the source does not play the latest release
     * @throws UnknownFlow                     when the latest release has no Flow with the given key
     * @throws InvalidCharacter                when a Character id is blank
     * @throws UnknownCharacter                when the source holds no Character with an id
     * @throws CharacterAlreadyExists          when an id is given twice
     * @throws InvalidCampaignName
     */
    public function __invoke(TransferCampaign $command): void
    {
        $source = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $id = CampaignId::fromString($command->newCampaignId);
        if ($this->campaigns->ofId($id) instanceof Campaign) {
            throw CampaignAlreadyExists::withId($id);
        }

        $latest = $this->releases->get($source->pinnedRelease()->gameSystemKey());

        $this->campaigns->add($source->transfer(
            $id,
            $command->name,
            $latest,
            $command->flowKey,
            array_map(CharacterId::fromString(...), $command->characterIds),
            $this->clock->now(),
        ));
    }
}
