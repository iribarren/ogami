<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Shared\Application\Bus\QueryHandler;

final readonly class GetCampaignReleaseHandler implements QueryHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private PublishedReleaseContents $contents,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws GameSystemReleaseNotFound when the pinned release can no longer be found
     * @throws InvalidGameSystemRelease  when the pinned release can no longer be read
     */
    public function __invoke(GetCampaignRelease $query): ReleaseContentView
    {
        $pinned = $this->ownedCampaigns->get($query->campaignId, $query->userId)->pinnedRelease();

        return $this->contents->content($pinned->gameSystemKey(), $pinned->releaseVersion());
    }
}
