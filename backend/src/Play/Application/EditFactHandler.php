<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\InvalidFact;
use App\Play\Domain\Campaign\Facts\UnknownFact;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\Lists\UnknownThread;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class EditFactHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private PublishedGameSystemReleases $releases,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws UnknownFact                     when the campaign holds no fact with this id, or a link names none
     * @throws UnknownNpc                      when a link names an NPC the campaign does not hold
     * @throws UnknownThread                   when a link names a Thread the campaign does not hold
     * @throws InvalidFact                     when a value breaks a rule, a free fact lacks its label or text, or a slot fact is given one
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function __invoke(EditFact $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        // The id is resolved before the release is read: an unknown fact must not surface as a release failure.
        $factId = FactId::fromString($command->factId);
        $fact = $campaign->fact($factId) ?? throw UnknownFact::withId($factId);
        // The edit replaces a free fact's label and text, so both must be sent; the domain alone would keep a missing one.
        if ($fact->isFree() && (null === $command->label || null === $command->text)) {
            throw InvalidFact::because('A free fact needs both its label and its text.');
        }

        $pinned = $campaign->pinnedRelease();
        $release = $this->releases->get($pinned->gameSystemKey(), $pinned->releaseVersion());

        $campaign->editFact($factId, $command->label, $command->text, AddFactHandler::links($command->links), $release);
        $this->campaigns->save($campaign);
    }
}
