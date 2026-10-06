<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignAlreadyExists;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\InvalidCampaignId;
use App\Play\Domain\Campaign\InvalidCampaignName;
use App\Play\Domain\Campaign\InvalidCampaignOwner;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Shared\Application\Bus\CommandHandler;

final readonly class CreateCampaignHandler implements CommandHandler
{
    public function __construct(
        private CampaignRepository $campaigns,
        private PublishedGameSystemReleases $releases,
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvalidCampaignId
     * @throws CampaignAlreadyExists     when the id is already taken (checked first, whatever the repository does)
     * @throws GameSystemReleaseNotFound when the GameSystem has no published release
     * @throws InvalidCampaignOwner
     * @throws InvalidCampaignName
     */
    public function __invoke(CreateCampaign $command): void
    {
        $id = CampaignId::fromString($command->campaignId);
        if ($this->campaigns->ofId($id) instanceof Campaign) {
            throw CampaignAlreadyExists::withId($id);
        }

        $latest = $this->releases->get($command->gameSystemKey);

        $this->campaigns->add(Campaign::create(
            $id,
            $command->ownerId,
            $command->name,
            PinnedRelease::of($latest->gameSystemKey(), $latest->releaseVersion(), $latest->name()),
            $this->clock->now(),
        ));
    }
}
