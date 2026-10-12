<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Lists\InvalidThread;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\Campaign\Lists\UnknownThread;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class EditThreadHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private PublishedGameSystemReleases $releases,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws UnknownThread                   when the campaign holds no Thread with this id
     * @throws InvalidThread                   when a value breaks a rule
     * @throws UnknownReleaseTag               when a Tag is not in the pinned release
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function __invoke(EditThread $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        // The id is resolved before the release is read: an unknown Thread must not surface as a release failure.
        $threadId = ThreadId::fromString($command->threadId);
        if (!$campaign->thread($threadId) instanceof \App\Play\Domain\Campaign\Lists\Thread) {
            throw UnknownThread::withId($threadId);
        }

        $pinned = $campaign->pinnedRelease();
        // The Tags come from the pinned release, never the latest one (ADR 0014).
        $release = $this->releases->get($pinned->gameSystemKey(), $pinned->releaseVersion());

        $campaign->editThread($threadId, $command->name, $command->tags, $command->freeTags, $command->weight, $release);
        $this->campaigns->save($campaign);
    }
}
