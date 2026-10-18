<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Upgrade\CampaignUpToDate;
use App\Play\Domain\Campaign\Upgrade\ReleaseUpgradeChanged;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class UpgradeCampaignReleaseHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private PublishedGameSystemReleases $releases,
        private Clock $clock,
    ) {
    }

    /**
     * Only the latest release is an upgrade target (ADR 0035): a version that is not published, or
     * no longer the latest, fails like a plan that changed since the preview.
     *
     * @throws CampaignNotFound
     * @throws CampaignUpToDate                when the version is not newer than the pinned one
     * @throws ReleaseUpgradeChanged           when the version is not the latest release, or the plan is no longer the confirmed one
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function __invoke(UpgradeCampaignRelease $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $pinned = $campaign->pinnedRelease();
        $current = PinnedReleases::of($this->releases, $campaign);
        if ($command->toVersion <= $pinned->releaseVersion()) {
            throw CampaignUpToDate::at($pinned->releaseVersion(), $command->toVersion);
        }

        try {
            $target = $this->releases->get($pinned->gameSystemKey(), $command->toVersion);
        } catch (GameSystemReleaseNotFound) {
            throw ReleaseUpgradeChanged::sincePreview();
        }

        if ($this->releases->get($pinned->gameSystemKey())->releaseVersion() !== $target->releaseVersion()) {
            throw ReleaseUpgradeChanged::sincePreview();
        }

        $campaign->upgradeRelease($current, $target, $command->fingerprint, $this->clock->now());
        $this->campaigns->save($campaign);
    }
}
