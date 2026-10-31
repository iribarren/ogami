<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Lists\InvalidPlace;
use App\Play\Domain\Campaign\Lists\Place;
use App\Play\Domain\Campaign\Lists\PlaceId;
use App\Play\Domain\Campaign\Lists\TagNotApplicable;
use App\Play\Domain\Campaign\Lists\UnknownPlace;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class EditPlaceHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private PublishedGameSystemReleases $releases,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws UnknownPlace                    when the campaign holds no Place with this id
     * @throws InvalidPlace                    when a value breaks a rule
     * @throws UnknownReleaseTag               when a Tag is not in the pinned release
     * @throws TagNotApplicable                when a Tag is limited to other kinds than Places
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function __invoke(EditPlace $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        // The id is resolved before the release is read: an unknown Place must not surface as a release failure.
        $placeId = PlaceId::fromString($command->placeId);
        if (!$campaign->place($placeId) instanceof Place) {
            throw UnknownPlace::withId($placeId);
        }

        $pinned = $campaign->pinnedRelease();
        // The Tags come from the pinned release, never the latest one (ADR 0014).
        $release = $this->releases->get($pinned->gameSystemKey(), $pinned->releaseVersion());

        $campaign->editPlace($placeId, $command->name, $command->notes, $command->tags, $command->freeTags, $command->weight, $release);
        $this->campaigns->save($campaign);
    }
}
