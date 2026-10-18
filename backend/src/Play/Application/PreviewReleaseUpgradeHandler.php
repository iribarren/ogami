<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\QueryHandler;

final readonly class PreviewReleaseUpgradeHandler implements QueryHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private PublishedGameSystemReleases $releases,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws GameSystemReleaseNotFound       when the pinned or the latest release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function __invoke(PreviewReleaseUpgrade $query): ReleaseUpgradePreview
    {
        $campaign = $this->ownedCampaigns->get($query->campaignId, $query->userId);
        $pinned = $campaign->pinnedRelease();
        $current = PinnedReleases::of($this->releases, $campaign);
        $latest = $this->releases->get($pinned->gameSystemKey());
        if ($latest->releaseVersion() <= $pinned->releaseVersion()) {
            return new ReleaseUpgradePreview($pinned->releaseVersion(), null);
        }

        return new ReleaseUpgradePreview($pinned->releaseVersion(), $campaign->planReleaseUpgrade($current, $latest));
    }
}
