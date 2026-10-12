<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\CampaignRepository;
use App\Play\Domain\Campaign\Facts\FactAlreadyExists;
use App\Play\Domain\Campaign\Facts\FactFill;
use App\Play\Domain\Campaign\Facts\InvalidFact;
use App\Play\Domain\Campaign\Facts\UnknownFactSlot;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\InvalidThread;
use App\Play\Domain\Campaign\Lists\NpcAlreadyExists;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\ThreadAlreadyExists;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\Lists\UnknownThread;
use App\Play\Domain\GameSystem\FactSlotType;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Shared\Application\Bus\CommandHandler;

final readonly class FillFactSlotHandler implements CommandHandler
{
    public function __construct(
        private OwnedCampaigns $ownedCampaigns,
        private CampaignRepository $campaigns,
        private PublishedGameSystemReleases $releases,
        private FactIdGenerator $factIds,
        private NpcIdGenerator $npcIds,
        private ThreadIdGenerator $threadIds,
    ) {
    }

    /**
     * @throws CampaignNotFound
     * @throws UnknownFactSlot                 when the pinned release declares no slot with this key
     * @throws InvalidFact                     when not exactly one value is sent, or it does not fit the slot's type or breaks a rule
     * @throws UnknownNpc                      when the subject is an NPC the campaign does not hold
     * @throws UnknownThread                   when the subject is a Thread the campaign does not hold
     * @throws InvalidNpc                      when a new NPC's name breaks a rule
     * @throws InvalidThread                   when a new Thread's name breaks a rule
     * @throws FactAlreadyExists               when a new fact's id is already held
     * @throws NpcAlreadyExists                when a new NPC's id is already held
     * @throws ThreadAlreadyExists             when a new Thread's id is already held
     * @throws CampaignLimitReached            when a new fact, NPC or Thread would pass its 500 limit
     * @throws CampaignModifiedConcurrently    when another request saved the campaign meanwhile
     * @throws GameSystemReleaseNotFound       when the pinned release can no longer be read
     * @throws UnsupportedReleaseSchemaVersion
     * @throws InvalidGameSystemRelease
     */
    public function __invoke(FillFactSlot $command): void
    {
        $campaign = $this->ownedCampaigns->get($command->campaignId, $command->userId);
        $pinned = $campaign->pinnedRelease();
        $release = $this->releases->get($pinned->gameSystemKey(), $pinned->releaseVersion());
        $type = $release->factSlot($command->slot)->type ?? throw UnknownFactSlot::withKey($command->slot);

        // A fresh fact id every time: the domain keeps the id of a filled slot's fact.
        $campaign->fillFactSlot($this->factIds->generate(), $command->slot, $this->fill($command, $type), $release);
        $this->campaigns->save($campaign);
    }

    /**
     * @throws InvalidFact when not exactly one value is sent, or a subject or name is sent for a text slot
     */
    private function fill(FillFactSlot $command, FactSlotType $type): FactFill
    {
        if (1 !== \count(array_filter([$command->text, $command->subject, $command->name], static fn (?string $value): bool => null !== $value))) {
            throw InvalidFact::because('Send exactly one of text, subject and name.');
        }

        if (null !== $command->text) {
            return FactFill::text($command->text);
        }

        return match ($type) {
            FactSlotType::Npc => null === $command->subject ? FactFill::newNpc($this->npcIds->generate(), (string) $command->name) : FactFill::existingNpc(NpcId::fromString($command->subject)),
            FactSlotType::Thread => null === $command->subject ? FactFill::newThread($this->threadIds->generate(), (string) $command->name) : FactFill::existingThread(ThreadId::fromString($command->subject)),
            FactSlotType::Text => throw InvalidFact::because('A "text" slot takes a text.'),
        };
    }
}
