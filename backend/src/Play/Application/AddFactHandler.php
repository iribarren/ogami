<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Facts\FactAlreadyExists;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\Facts\FactLinkKind;
use App\Play\Domain\Campaign\Facts\InvalidFact;
use App\Play\Domain\Campaign\Facts\UnknownFact;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\Lists\UnknownThread;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class AddFactHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private PublishedGameSystemReleases $releases,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws InvalidFact                     when a value breaks a rule, or the id is blank
     * @throws UnknownNpc                      when a link names an NPC the campaign does not hold
     * @throws UnknownThread                   when a link names a Thread the campaign does not hold
     * @throws UnknownFact                     when a link names a fact the campaign does not hold
     * @throws FactAlreadyExists               when the campaign holds a fact with this id
     * @throws CampaignLimitReached            when the campaign already holds 500 facts
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function __invoke(AddFact $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $pinned = $campaign->pinnedRelease();
        $release = $this->releases->get($pinned->gameSystemKey(), $pinned->releaseVersion());

        $campaign->addFreeFact(FactId::fromString($command->factId), $command->label, $command->text, self::links($command->links), $release);
        $this->campaigns->save($campaign);
    }

    /**
     * @param list<array{kind: string, id: string}> $links
     *
     * @return list<FactLink>
     *
     * @throws InvalidFact when a link has an unknown kind or names no target
     */
    public static function links(array $links): array
    {
        return array_map(static fn (array $link): FactLink => FactLink::of(FactLinkKind::tryFrom($link['kind']) ?? throw InvalidFact::because(\sprintf('A link is to an "npc", "thread" or "fact", not "%s".', $link['kind'])), $link['id']), $links);
    }
}
