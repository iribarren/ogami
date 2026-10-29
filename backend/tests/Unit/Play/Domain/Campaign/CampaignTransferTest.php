<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterAlreadyExists;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\UnknownCharacter;
use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunStatus;
use App\Play\Domain\Campaign\InvalidCampaignName;
use App\Play\Domain\Campaign\Lists\ItemStatus;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\Transfer\TransferFromOutdatedRelease;
use App\Play\Domain\Campaign\Transfer\TransferToAnotherGameSystem;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Tracker;
use App\Play\Domain\GameSystem\UnknownFlow;
use App\Tests\Support\Play\Snapshots;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A transfer starts a new campaign from an existing one (ADR 0046): a fresh campaign of the same
 * release that carries the NPCs, the Threads, the Campaign facts and the chosen Characters, with
 * their ids. The source is never changed.
 */
#[CoversClass(Campaign::class)]
#[CoversClass(Npc::class)]
#[CoversClass(TransferFromOutdatedRelease::class)]
#[CoversClass(TransferToAnotherGameSystem::class)]
final class CampaignTransferTest extends TestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $flows = Snapshots::withFlows('heist', 'Heist', 2)->flows();
        $this->release = new GameSystemSnapshot('heist', 'Heist', 2, null, [], [], [Tracker::clock('alarm', 'Alarm', 'Lockdown', 6), Tracker::counter('heat', 'Heat', null, -5, 5, -5)], flows: $flows);
    }

    #[Test]
    public function theNewCampaignPlaysTheLatestReleaseUnderANewIdAndNameForTheSameOwner(): void
    {
        $source = $this->source();

        $new = $source->transfer(CampaignId::fromString('campaign-2'), '  Making a Name  ', $this->release, 'one-shot', [], new \DateTimeImmutable('2026-10-30T10:00:00+00:00'));

        self::assertSame(['campaign-2', 'user-1', 'Making a Name', 'heist', 2, 'Heist'], [$new->id()->toString(), $new->ownerId(), $new->name(), $new->pinnedRelease()->gameSystemKey(), $new->pinnedRelease()->releaseVersion(), $new->pinnedRelease()->gameSystemName()]);
        self::assertEquals(new \DateTimeImmutable('2026-10-30T10:00:00+00:00'), $new->createdAt());
        self::assertSame(1, $new->version());
    }

    #[Test]
    public function trackersStartAtTheirInitialValueWhateverTheSourceHad(): void
    {
        $new = $this->source()->transfer(CampaignId::fromString('campaign-2'), 'Making a Name', $this->release, null, [], new \DateTimeImmutable());

        self::assertSame(['alarm' => 0, 'heat' => -5], $new->trackerValues());
    }

    #[Test]
    public function aFlowStartsAFreshFlowRunWithNoSessionsAndNoFlowStartsFreePlay(): void
    {
        $source = $this->source();

        $guided = $source->transfer(CampaignId::fromString('campaign-2'), 'Guided', $this->release, 'one-shot', [], new \DateTimeImmutable());
        $free = $source->transfer(CampaignId::fromString('campaign-3'), 'Free', $this->release, null, [], new \DateTimeImmutable());

        self::assertSame('one-shot', $guided->flowKey());
        self::assertEquals(FlowRun::start(), $guided->flowRun());
        self::assertSame([], $guided->sessions());
        self::assertNull($free->flowKey());
        self::assertNull($free->flowRun());
        self::assertSame([], $free->sessions());
    }

    #[Test]
    public function theSourcesOwnFlowCanBePlayedAgain(): void
    {
        $new = $this->source()->transfer(CampaignId::fromString('campaign-2'), 'Sequel', $this->release, 'the-heist', [], new \DateTimeImmutable());

        self::assertSame('the-heist', $new->flowKey());
        self::assertSame(FlowRunStatus::Active, $new->flowRun()?->status());
    }

    #[Test]
    public function everyNpcThreadAndFactComesAlongWithItsId(): void
    {
        $source = $this->source();

        $new = $source->transfer(CampaignId::fromString('campaign-2'), 'Making a Name', $this->release, null, [CharacterId::fromString('c-2')], new \DateTimeImmutable());

        self::assertEquals($source->npcs(), $new->npcs());
        self::assertEquals($source->threads(), $new->threads());
        self::assertEquals($source->facts($this->release), $new->facts($this->release));
        self::assertSame(['npc-1', 'npc-2'], array_map(static fn (Npc $npc): string => $npc->id()->toString(), $new->npcs()));
        self::assertSame(['thread-1'], array_map(static fn (Thread $thread): string => $thread->id()->toString(), $new->threads()));
        self::assertSame(ItemStatus::Closed, $new->npc(NpcId::fromString('npc-2'))?->status());
    }

    #[Test]
    public function onlyTheChosenCharactersComeAlongInTheOrderOfTheSource(): void
    {
        $source = $this->source();

        $new = $source->transfer(CampaignId::fromString('campaign-2'), 'Making a Name', $this->release, null, [CharacterId::fromString('c-3'), CharacterId::fromString('c-1')], new \DateTimeImmutable());

        self::assertSame(['c-1', 'c-3'], array_map(static fn (Character $character): string => $character->id()->toString(), $new->characters()));
        self::assertEquals([$source->characters()[0], $source->characters()[2]], $new->characters());
    }

    #[Test]
    public function noCharacterIsCarriedUnlessChosen(): void
    {
        $new = $this->source()->transfer(CampaignId::fromString('campaign-2'), 'Making a Name', $this->release, null, [], new \DateTimeImmutable());

        self::assertSame([], $new->characters());
    }

    #[Test]
    public function aPromotedNpcKeepsItsDataButLosesThePromotionWhenItsCharacterStaysBehind(): void
    {
        $new = $this->source()->transfer(CampaignId::fromString('campaign-2'), 'Making a Name', $this->release, null, [CharacterId::fromString('c-1')], new \DateTimeImmutable());

        $npc = $new->npc(NpcId::fromString('npc-1'));
        self::assertInstanceOf(Npc::class, $npc);
        self::assertSame(['Mira', 'Wants the ledger', ItemStatus::Closed, null], [$npc->name(), $npc->agenda(), $npc->status(), $npc->promotedTo()?->toString()]);
    }

    #[Test]
    public function aFactWhoseSubjectIsAnNpcPromotedToALeftOutCharacterIsCarriedWithItsSubject(): void
    {
        $source = $this->source([Fact::of(FactId::fromString('fact-2'), 'contact', null, null, FactLink::npc(NpcId::fromString('npc-1')), [])]);

        $new = $source->transfer(CampaignId::fromString('campaign-2'), 'Making a Name', $this->release, null, [CharacterId::fromString('c-1')], new \DateTimeImmutable());

        $fact = $new->fact(FactId::fromString('fact-2'));
        self::assertInstanceOf(Fact::class, $fact);
        self::assertEquals(FactLink::npc(NpcId::fromString('npc-1')), $fact->subject());
        self::assertNull($new->npc(NpcId::fromString('npc-1'))?->promotedTo());
        self::assertNull($new->character(CharacterId::fromString('c-2')));
        self::assertCount(2, $new->facts($this->release));
    }

    #[Test]
    public function aCarriedCharacterKeepsItsPromotionWithItsNpc(): void
    {
        $new = $this->source()->transfer(CampaignId::fromString('campaign-2'), 'Making a Name', $this->release, null, [CharacterId::fromString('c-2')], new \DateTimeImmutable());

        self::assertSame('npc-1', $new->characters()[0]->promotedFrom()?->toString());
        self::assertSame('c-2', $new->npc(NpcId::fromString('npc-1'))?->promotedTo()?->toString());
    }

    #[Test]
    public function theSourceIsNotChanged(): void
    {
        $source = $this->source();
        $before = serialize($source);

        $source->transfer(CampaignId::fromString('campaign-2'), 'Making a Name', $this->release, 'one-shot', [CharacterId::fromString('c-1')], new \DateTimeImmutable());

        self::assertSame($before, serialize($source));
        self::assertSame(1, $source->version());
        self::assertSame('c-2', $source->npc(NpcId::fromString('npc-1'))?->promotedTo()?->toString());
        self::assertCount(1, $source->sessions());
        self::assertSame(['alarm' => 3, 'heat' => 2], $source->trackerValues());
    }

    #[Test]
    public function aReleaseOfAnotherGameSystemIsRefused(): void
    {
        $this->expectException(TransferToAnotherGameSystem::class);

        $this->source()->transfer(CampaignId::fromString('campaign-2'), 'Making a Name', Snapshots::bare('ironsworn', 'Ironsworn', 2), null, [], new \DateTimeImmutable());
    }

    #[Test]
    public function aSourceThatIsNotOnTheGivenReleaseIsRefused(): void
    {
        $outdated = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable());

        try {
            $outdated->transfer(CampaignId::fromString('campaign-2'), 'Making a Name', $this->release, null, [], new \DateTimeImmutable());
            self::fail('A campaign on an older release was transferred.');
        } catch (TransferFromOutdatedRelease $exception) {
            self::assertSame('The campaign plays release 1 and release 2 is the latest: upgrade the campaign first.', $exception->getMessage());
        }
    }

    #[Test]
    public function aFlowTheReleaseDoesNotHaveIsRefused(): void
    {
        $this->expectException(UnknownFlow::class);

        $this->source()->transfer(CampaignId::fromString('campaign-2'), 'Making a Name', $this->release, 'the-long-con', [], new \DateTimeImmutable());
    }

    #[Test]
    public function anUnknownCharacterIdIsRefused(): void
    {
        $this->expectException(UnknownCharacter::class);

        $this->source()->transfer(CampaignId::fromString('campaign-2'), 'Making a Name', $this->release, null, [CharacterId::fromString('ghost')], new \DateTimeImmutable());
    }

    #[Test]
    public function aCharacterIdGivenTwiceIsRefused(): void
    {
        $this->expectException(CharacterAlreadyExists::class);

        $this->source()->transfer(CampaignId::fromString('campaign-2'), 'Making a Name', $this->release, null, [CharacterId::fromString('c-1'), CharacterId::fromString('c-1')], new \DateTimeImmutable());
    }

    #[Test]
    public function aBlankOrTooLongNameIsRefused(): void
    {
        foreach (['   ', str_repeat('a', Campaign::MAX_NAME_LENGTH + 1)] as $name) {
            try {
                $this->source()->transfer(CampaignId::fromString('campaign-2'), $name, $this->release, null, [], new \DateTimeImmutable());
                self::fail('The name was accepted.');
            } catch (InvalidCampaignName) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Release 2 campaign "The job" on the one-shot with: NPC "npc-1" (promoted to "c-2", a legacy
     * pair), a closed NPC, a Thread, a fact linking them, three Characters ("c-2" from "npc-1"),
     * one session and moved tracker values.
     *
     * @param list<Fact> $extraFacts facts added after "fact-1"
     */
    private function source(array $extraFacts = []): Campaign
    {
        $npcs = [
            Npc::of(NpcId::fromString('npc-1'), 'Mira', 'Wants the ledger', ['ally'], ['Fence'], 3, ItemStatus::Closed, CharacterId::fromString('c-2')),
            Npc::of(NpcId::fromString('npc-2'), 'Brick', null, [], [], 1, ItemStatus::Closed),
        ];
        $threads = [Thread::of(ThreadId::fromString('thread-1'), 'The vault', [], ['Gig'], 2)];
        $facts = [
            Fact::of(FactId::fromString('fact-1'), null, 'Crew', 'Met in a bar', null, [FactLink::npc(NpcId::fromString('npc-1')), FactLink::thread(ThreadId::fromString('thread-1'))]),
        ];
        $facts = [...$facts, ...$extraFacts];
        $characters = [
            Character::of(CharacterId::fromString('c-1'), 'Vi', [], [], []),
            Character::of(CharacterId::fromString('c-2'), 'Mira', ['ally'], ['Fence'], [], NpcId::fromString('npc-1')),
            Character::of(CharacterId::fromString('c-3'), 'Jax', [], [], []),
        ];
        $source = Campaign::reconstitute(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 2, 'Heist'), new \DateTimeImmutable('2026-10-01T09:00:00+00:00'), [], ['alarm' => 3, 'heat' => 2], 'one-shot', FlowRun::start(), $npcs, $threads, $facts, $characters);
        $source->startSession(new \DateTimeImmutable('2026-10-02T09:00:00+00:00'));

        return $source;
    }
}
