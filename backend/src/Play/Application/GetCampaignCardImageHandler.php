<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Shared\Application\Bus\QueryHandler;

final readonly class GetCampaignCardImageHandler implements QueryHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private PublishedReleaseContents $contents,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws GameSystemReleaseNotFound when the pinned release can no longer be found
     * @throws CardImageNotFound         when the pinned release did not pin an image with this hash
     */
    public function __invoke(GetCampaignCardImage $query): CardImageBytes
    {
        $pinned = $this->ownedCampaigns->get($query->campaignId, $query->userId)->pinnedRelease();

        return $this->contents->cardImage($pinned->gameSystemKey(), $pinned->releaseVersion(), $query->sha256);
    }
}
