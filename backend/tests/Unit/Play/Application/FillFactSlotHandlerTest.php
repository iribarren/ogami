<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\EditFact;
use App\Play\Application\EditFactHandler;
use App\Play\Application\FactIdGenerator;
use App\Play\Application\FillFactSlot;
use App\Play\Application\FillFactSlotHandler;
use App\Play\Application\NpcIdGenerator;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\ThreadIdGenerator;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\Facts\InvalidFact;
use App\Play\Domain\Campaign\Facts\UnknownFactSlot;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\InvalidThread;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\Lists\UnknownThread;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\FactSlotType;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FillFactSlot::class)]
#[CoversClass(FillFactSlotHandler::class)]
final class FillFactSlotHandlerTest extends TestCase
{
    private GameSystemSnapshot $release;
    private InMemoryCampaignRepository $campaigns;
    private FillFactSlotHandler $fill;
    private EditFactHandler $edit;

    protected function setUp(): void
    {
        $this->release = new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], factSlots: [
            new FactSlot('city', 'City', FactSlotType::Text),
            new FactSlot('prince', 'Prince', FactSlotType::Npc),
            new FactSlot('goal', 'Goal', FactSlotType::Thread),
        ]);
        $releases = new InMemoryPublishedGameSystemReleases();
        $releases->add($this->release);
        $this->campaigns = new InMemoryCampaignRepository();
        $campaign = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00'));
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $this->release);
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Vault', [], [], 1, $this->release);
        $this->campaigns->add($campaign);
        $owned = new OwnedCampaigns($this->campaigns);
        $this->fill = new FillFactSlotHandler($owned, $this->campaigns, $releases, $this->factIds(), $this->npcIds(), $this->threadIds());
        $this->edit = new EditFactHandler($owned, $this->campaigns, $releases);
    }

    #[Test]
    public function itFillsATextSlotWithTheTrimmedTextUnderANewFactId(): void
    {
        ($this->fill)(new FillFactSlot('campaign-1', 'user-1', 'city', '  Ashford ', null, null));

        $fact = $this->slotFact('city');
        self::assertSame(['fact-1', 'Ashford', null], [$fact->id()->toString(), $fact->text(), $fact->subject()]);
    }

    #[Test]
    public function itFillsAnNpcOrThreadSlotWithAnExistingItem(): void
    {
        ($this->fill)(new FillFactSlot('campaign-1', 'user-1', 'prince', null, 'npc-1', null));
        ($this->fill)(new FillFactSlot('campaign-1', 'user-1', 'goal', null, 'thread-1', null));

        self::assertSame(['npc:npc-1', 'thread:thread-1'], [$this->subjectOf($this->slotFact('prince')), $this->subjectOf($this->slotFact('goal'))]);
        self::assertCount(1, $this->campaign()->npcs());
        self::assertCount(1, $this->campaign()->threads());
    }

    #[Test]
    public function itCreatesAnOpenNpcOrThreadByNameAndFillsTheSlotWithIt(): void
    {
        ($this->fill)(new FillFactSlot('campaign-1', 'user-1', 'prince', null, null, ' Valen '));
        ($this->fill)(new FillFactSlot('campaign-1', 'user-1', 'goal', null, null, 'Find the ledger'));

        $campaign = $this->campaign();
        self::assertSame(['npc:npc-2', 'thread:thread-2'], [$this->subjectOf($this->slotFact('prince')), $this->subjectOf($this->slotFact('goal'))]);
        $npc = $campaign->npc(NpcId::fromString('npc-2'));
        $thread = $campaign->thread(ThreadId::fromString('thread-2'));
        self::assertNotNull($npc);
        self::assertNotNull($thread);
        self::assertSame(['Valen', 1, true, 'Find the ledger', 1, true], [$npc->name(), $npc->weight(), $npc->isOpen(), $thread->name(), $thread->weight(), $thread->isOpen()]);
    }

    #[Test]
    public function aRefillKeepsTheFactIdAndItsLinks(): void
    {
        ($this->fill)(new FillFactSlot('campaign-1', 'user-1', 'city', 'Ashford', null, null));
        ($this->edit)(new EditFact('campaign-1', 'user-1', 'fact-1', null, null, [['kind' => 'npc', 'id' => 'npc-1']]));

        ($this->fill)(new FillFactSlot('campaign-1', 'user-1', 'city', 'Brightwater', null, null));

        $fact = $this->slotFact('city');
        self::assertSame(['fact-1', 'Brightwater', ['npc:npc-1']], [$fact->id()->toString(), $fact->text(), array_map(static fn (FactLink $link): string => $link->kind->value.':'.$link->id, $fact->links())]);
        self::assertCount(1, $this->campaign()->facts($this->release));
    }

    #[Test]
    public function aRefusedFillChangesNothingNotEvenANewNpc(): void
    {
        $before = $this->snapshot();

        foreach ([
            [new FillFactSlot('campaign-1', 'user-1', 'nowhere', 'Text', null, null), UnknownFactSlot::class],
            [new FillFactSlot('campaign-1', 'user-1', 'city', null, null, null), InvalidFact::class],
            [new FillFactSlot('campaign-1', 'user-1', 'city', 'Text', 'npc-1', null), InvalidFact::class],
            [new FillFactSlot('campaign-1', 'user-1', 'prince', null, 'npc-1', 'Valen'), InvalidFact::class],
            [new FillFactSlot('campaign-1', 'user-1', 'city', ' ', null, null), InvalidFact::class],
            [new FillFactSlot('campaign-1', 'user-1', 'prince', 'Text', null, null), InvalidFact::class],
            [new FillFactSlot('campaign-1', 'user-1', 'city', null, 'npc-1', null), InvalidFact::class],
            [new FillFactSlot('campaign-1', 'user-1', 'city', null, null, 'Valen'), InvalidFact::class],
            [new FillFactSlot('campaign-1', 'user-1', 'prince', null, 'missing', null), UnknownNpc::class],
            [new FillFactSlot('campaign-1', 'user-1', 'goal', null, 'missing', null), UnknownThread::class],
            [new FillFactSlot('campaign-1', 'user-1', 'prince', null, null, ' '), InvalidNpc::class],
            [new FillFactSlot('campaign-1', 'user-1', 'goal', null, null, str_repeat('a', 201)), InvalidThread::class],
        ] as [$command, $error]) {
            try {
                ($this->fill)($command);
                self::fail('The refused fill was accepted.');
            } catch (\Throwable $exception) {
                self::assertInstanceOf($error, $exception);
            }
        }

        self::assertEquals($before, $this->campaign());
    }

    #[Test]
    public function aRefusedRefillOfAFilledSlotKeepsItsValueAndLinks(): void
    {
        ($this->fill)(new FillFactSlot('campaign-1', 'user-1', 'city', 'Ashford', null, null));
        ($this->fill)(new FillFactSlot('campaign-1', 'user-1', 'prince', null, 'npc-1', null));
        ($this->edit)(new EditFact('campaign-1', 'user-1', 'fact-1', null, null, [['kind' => 'npc', 'id' => 'npc-1']]));
        $before = $this->snapshot();

        foreach ([
            [new FillFactSlot('campaign-1', 'user-1', 'city', ' ', null, null), InvalidFact::class],
            [new FillFactSlot('campaign-1', 'user-1', 'city', null, 'npc-1', null), InvalidFact::class],
            [new FillFactSlot('campaign-1', 'user-1', 'prince', null, 'missing', null), UnknownNpc::class],
            [new FillFactSlot('campaign-1', 'user-1', 'prince', null, null, ' '), InvalidNpc::class],
        ] as [$command, $error]) {
            try {
                ($this->fill)($command);
                self::fail('The refused refill was accepted.');
            } catch (\Throwable $exception) {
                self::assertInstanceOf($error, $exception);
            }
        }

        self::assertEquals($before, $this->campaign());
        $fact = $this->slotFact('city');
        self::assertSame(['fact-1', 'Ashford', ['npc:npc-1']], [$fact->id()->toString(), $fact->text(), array_map(static fn (FactLink $link): string => $link->kind->value.':'.$link->id, $fact->links())]);
        self::assertSame('npc:npc-1', $this->subjectOf($this->slotFact('prince')));
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);

        ($this->fill)(new FillFactSlot('campaign-1', 'user-2', 'city', 'Ashford', null, null));
    }

    private function subjectOf(Fact $fact): string
    {
        $subject = $fact->subject();

        return $subject instanceof FactLink ? $subject->kind->value.':'.$subject->id : '';
    }

    private function campaign(): Campaign
    {
        return $this->campaigns->ofId(CampaignId::fromString('campaign-1')) ?? throw new \LogicException('The campaign is stored.');
    }

    /**
     * An independent copy of the stored campaign, so that a later change cannot reach it.
     */
    private function snapshot(): Campaign
    {
        $copy = unserialize(serialize($this->campaign()));
        self::assertInstanceOf(Campaign::class, $copy);

        return $copy;
    }

    private function slotFact(string $slot): Fact
    {
        return array_find($this->campaign()->facts($this->release), static fn (Fact $fact): bool => $fact->slot() === $slot) ?? throw new \LogicException('The slot fact is stored.');
    }

    private function factIds(): FactIdGenerator
    {
        return new class implements FactIdGenerator {
            private int $count = 0;

            public function generate(): FactId
            {
                return FactId::fromString('fact-'.++$this->count);
            }
        };
    }

    private function npcIds(): NpcIdGenerator
    {
        return new class implements NpcIdGenerator {
            private int $count = 1;

            public function generate(): NpcId
            {
                return NpcId::fromString('npc-'.++$this->count);
            }
        };
    }

    private function threadIds(): ThreadIdGenerator
    {
        return new class implements ThreadIdGenerator {
            private int $count = 1;

            public function generate(): ThreadId
            {
                return ThreadId::fromString('thread-'.++$this->count);
            }
        };
    }
}
