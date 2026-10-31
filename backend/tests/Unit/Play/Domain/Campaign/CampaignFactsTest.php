<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactAlreadyExists;
use App\Play\Domain\Campaign\Facts\FactFill;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\Facts\FactLinkKind;
use App\Play\Domain\Campaign\Facts\InvalidFact;
use App\Play\Domain\Campaign\Facts\UnknownFact;
use App\Play\Domain\Campaign\Facts\UnknownFactSlot;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\InvalidPlace;
use App\Play\Domain\Campaign\Lists\ItemStatus;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcAlreadyExists;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\PlaceAlreadyExists;
use App\Play\Domain\Campaign\Lists\PlaceId;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\Lists\UnknownPlace;
use App\Play\Domain\Campaign\Lists\UnknownThread;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\FactSlotType;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A campaign keeps Campaign facts, free or filling the Fact slots of its release: every rule fails
 * closed, every command is all or nothing, and slot facts list first in release slot order.
 */
#[CoversClass(Campaign::class)]
#[CoversClass(Fact::class)]
#[CoversClass(FactFill::class)]
#[CoversClass(FactId::class)]
#[CoversClass(FactLink::class)]
#[CoversClass(CampaignLimitReached::class)]
final class CampaignFactsTest extends TestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], factSlots: [
            new FactSlot('city', 'City', FactSlotType::Text),
            new FactSlot('prince', 'The Prince', FactSlotType::Npc, 'Who rules?'),
            new FactSlot('goal', 'Goal', FactSlotType::Thread),
            new FactSlot('site', 'Site', FactSlotType::Place),
        ]);
    }

    #[Test]
    public function aNewCampaignHoldsNoFacts(): void
    {
        $campaign = $this->campaign();

        self::assertSame([], $campaign->facts($this->release));
        self::assertNull($campaign->fact($this->id('fact-1')));
    }

    #[Test]
    public function aFreeFactIsAddedWithTrimmedValuesAndItsLinks(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $this->release);
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Vault', [], [], 1, $this->release);
        $campaign->closeThread(ThreadId::fromString('thread-1'));
        $campaign->addFreeFact($this->id('fact-1'), 'Other', 'Other text', [], $this->release);

        $fact = $campaign->addFreeFact($this->id('fact-2'), '  Guild  ', "  Owes a debt \n", [FactLink::npc(NpcId::fromString('npc-1')), FactLink::thread(ThreadId::fromString('thread-1')), FactLink::fact($this->id('fact-1'))], $this->release);

        self::assertSame(['fact-2', null, 'Guild', 'Owes a debt', null], [$fact->id()->toString(), $fact->slot(), $fact->label(), $fact->text(), $fact->subject()]);
        self::assertSame(['npc:npc-1', 'thread:thread-1', 'fact:fact-1'], array_map(static fn (FactLink $link): string => $link->kind->value.':'.$link->id, $fact->links()));
        self::assertSame($fact, $campaign->fact($this->id('fact-2')));
        self::assertTrue($fact->isFree());
    }

    /**
     * @return iterable<string, array{string, string, list<FactLink>}>
     */
    public static function invalidFreeFacts(): iterable
    {
        yield 'blank label' => [' ', 'Text', []];
        yield 'label too long' => [str_repeat('a', 101), 'Text', []];
        yield 'blank text' => ['Label', " \n", []];
        yield 'text too long' => ['Label', str_repeat('a', 2001), []];
        yield 'duplicate link' => ['Label', 'Text', [FactLink::npc(NpcId::fromString('npc-1')), FactLink::npc(NpcId::fromString('npc-1'))]];
        yield 'self link' => ['Label', 'Text', [FactLink::fact(FactId::fromString('fact-1'))]];
        yield 'too many links' => ['Label', 'Text', [...array_map(static fn (int $i): FactLink => FactLink::fact(FactId::fromString('other-'.$i)), range(1, 21))]];
    }

    /**
     * @param list<FactLink> $links
     */
    #[Test]
    #[DataProvider('invalidFreeFacts')]
    public function aFreeFactBreakingARuleIsRefusedAndNothingIsAdded(string $label, string $text, array $links): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $this->release);

        try {
            $campaign->addFreeFact($this->id('fact-1'), $label, $text, $links, $this->release);
            self::fail('Expected InvalidFact.');
        } catch (InvalidFact) {
            self::assertSame([], $campaign->facts($this->release));
        }
    }

    #[Test]
    public function aLabelAndATextAtTheirLimitsAreAccepted(): void
    {
        $fact = $this->campaign()->addFreeFact($this->id('fact-1'), str_repeat('é', 100), str_repeat('é', 2000), [], $this->release);

        self::assertSame([100, 2000], [mb_strlen($fact->label() ?? ''), mb_strlen($fact->text() ?? '')]);
    }

    #[Test]
    public function twentyLinksAreAccepted(): void
    {
        $campaign = $this->campaign();
        $links = [];
        for ($i = 1; $i <= 20; ++$i) {
            $campaign->addFreeFact($this->id('target-'.$i), 'Target', 'Text', [], $this->release);
            $links[] = FactLink::fact($this->id('target-'.$i));
        }

        $fact = $campaign->addFreeFact($this->id('fact-1'), 'Label', 'Text', $links, $this->release);

        self::assertCount(20, $fact->links());
    }

    #[Test]
    public function aLinkNamingSomethingTheCampaignDoesNotHoldIsRefusedAndNothingIsAdded(): void
    {
        $campaign = $this->campaign();

        foreach ([
            [FactLink::npc(NpcId::fromString('ghost')), UnknownNpc::class],
            [FactLink::thread(ThreadId::fromString('ghost')), UnknownThread::class],
            [FactLink::fact($this->id('ghost')), UnknownFact::class],
        ] as [$link, $exception]) {
            try {
                $campaign->addFreeFact($this->id('fact-1'), 'Label', 'Text', [$link], $this->release);
                self::fail('Expected '.$exception);
            } catch (\DomainException $caught) {
                self::assertInstanceOf($exception, $caught);
            }
        }

        self::assertSame([], $campaign->facts($this->release));
    }

    #[Test]
    public function aFactIdAlreadyHeldIsRefusedWithoutReplacingTheFact(): void
    {
        $campaign = $this->campaign();
        $first = $campaign->addFreeFact($this->id('fact-1'), 'First', 'Text', [], $this->release);

        $this->expectException(FactAlreadyExists::class);

        try {
            $campaign->addFreeFact($this->id('fact-1'), 'Second', 'Text', [], $this->release);
        } finally {
            self::assertSame([$first], $campaign->facts($this->release));
        }
    }

    #[Test]
    public function aCampaignHoldsAtMost500Facts(): void
    {
        $campaign = $this->campaign();
        for ($i = 1; $i <= Campaign::MAX_FACTS; ++$i) {
            $campaign->addFreeFact($this->id('fact-'.$i), 'Fact '.$i, 'Text', [], $this->release);
        }

        $this->expectException(CampaignLimitReached::class);
        $this->expectExceptionMessageIsOrContains('A campaign holds at most 500 facts.');

        $campaign->addFreeFact($this->id('one-more'), 'More', 'Text', [], $this->release);
    }

    #[Test]
    public function aFullCampaignRefusesToFillAnUnfilledSlotAndCreatesNothing(): void
    {
        $campaign = $this->campaign();
        for ($i = 1; $i <= Campaign::MAX_FACTS; ++$i) {
            $campaign->addFreeFact($this->id('fact-'.$i), 'Fact '.$i, 'Text', [], $this->release);
        }

        try {
            $campaign->fillFactSlot($this->id('slot-fact'), 'prince', FactFill::newNpc(NpcId::fromString('npc-1'), 'Mira'), $this->release);
            self::fail('Expected CampaignLimitReached.');
        } catch (CampaignLimitReached) {
            self::assertSame([], $campaign->npcs());
            self::assertCount(Campaign::MAX_FACTS, $campaign->facts($this->release));
        }
    }

    #[Test]
    public function aTextSlotIsFilledAndRefilledKeepingItsIdAndLinks(): void
    {
        $campaign = $this->campaign();
        $campaign->addFreeFact($this->id('other'), 'Other', 'Text', [], $this->release);
        $filled = $campaign->fillFactSlot($this->id('fact-1'), 'city', FactFill::text('  Ashford  '), $this->release);
        $campaign->editFact($this->id('fact-1'), null, null, [FactLink::fact($this->id('other'))], $this->release);

        $refilled = $campaign->fillFactSlot($this->id('ignored'), 'city', FactFill::text('Brightwater'), $this->release);

        self::assertSame(['fact-1', 'city', null, 'Ashford'], [$filled->id()->toString(), $filled->slot(), $filled->label(), $filled->text()]);
        self::assertSame(['fact-1', 'city', 'Brightwater', ['fact:other']], [$refilled->id()->toString(), $refilled->slot(), $refilled->text(), array_map(static fn (FactLink $link): string => $link->kind->value.':'.$link->id, $refilled->links())]);
        self::assertNull($campaign->fact($this->id('ignored')));
        self::assertCount(2, $campaign->facts($this->release));
        self::assertFalse($refilled->isFree());
    }

    #[Test]
    public function anNpcSlotTakesAnExistingNpcClosedOrOpenAndARefillReplacesIt(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $this->release);
        $campaign->addNpc(NpcId::fromString('npc-2'), 'Brann', null, [], [], 1, $this->release);
        $campaign->closeNpc(NpcId::fromString('npc-1'));

        $first = $campaign->fillFactSlot($this->id('fact-1'), 'prince', FactFill::existingNpc(NpcId::fromString('npc-1')), $this->release);
        $second = $campaign->fillFactSlot($this->id('fact-2'), 'prince', FactFill::existingNpc(NpcId::fromString('npc-2')), $this->release);

        self::assertSame([FactLinkKind::Npc, 'npc-1', null], [$first->subject()?->kind, $first->subject()?->id, $first->text()]);
        self::assertSame(['fact-1', 'npc-2'], [$second->id()->toString(), $second->subject()?->id]);
    }

    #[Test]
    public function aThreadSlotTakesAnExistingThread(): void
    {
        $campaign = $this->campaign();
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Vault', [], [], 1, $this->release);

        $fact = $campaign->fillFactSlot($this->id('fact-1'), 'goal', FactFill::existingThread(ThreadId::fromString('thread-1')), $this->release);

        self::assertSame([FactLinkKind::Thread, 'thread-1'], [$fact->subject()?->kind, $fact->subject()?->id]);
    }

    #[Test]
    public function aPlaceSlotTakesAnExistingOrANewPlaceAndTheValueIsItsCurrentName(): void
    {
        $campaign = $this->campaign();
        $campaign->addPlace(PlaceId::fromString('place-1'), 'Saltmarsh', null, [], [], 1, $this->release);

        $fact = $campaign->fillFactSlot($this->id('fact-1'), 'site', FactFill::existingPlace(PlaceId::fromString('place-1')), $this->release);
        self::assertSame([FactLinkKind::Place, 'place-1', 'Saltmarsh'], [$fact->subject()?->kind, $fact->subject()?->id, $campaign->factValues($this->release)['site']]);

        $campaign->editPlace(PlaceId::fromString('place-1'), 'Saltmarsh Bay', null, [], [], 1, $this->release);
        self::assertSame('Saltmarsh Bay', $campaign->factValues($this->release)['site']);

        $refilled = $campaign->fillFactSlot($this->id('ignored'), 'site', FactFill::newPlace(PlaceId::fromString('place-2'), '  Dunmere '), $this->release);
        $place = $campaign->place(PlaceId::fromString('place-2'));
        self::assertSame(['fact-1', 'place-2', 'Dunmere', 1, ItemStatus::Open, null], [$refilled->id()->toString(), $refilled->subject()?->id, $place?->name(), $place?->weight(), $place?->status(), $place?->notes()]);
    }

    #[Test]
    public function aRefusedFillThatWouldCreateAPlaceCreatesNothing(): void
    {
        $campaign = $this->campaign();
        $campaign->addPlace(PlaceId::fromString('place-1'), 'Saltmarsh', null, [], [], 1, $this->release);

        foreach ([
            ['site', FactFill::newPlace(PlaceId::fromString('place-2'), ' '), InvalidPlace::class],
            ['site', FactFill::newPlace(PlaceId::fromString('place-1'), 'Twin'), PlaceAlreadyExists::class],
            ['goal', FactFill::newPlace(PlaceId::fromString('place-2'), 'Dunmere'), InvalidFact::class],
            ['site', FactFill::existingPlace(PlaceId::fromString('ghost')), UnknownPlace::class],
            ['site', FactFill::text('Saltmarsh'), InvalidFact::class],
            ['site', FactFill::existingThread(ThreadId::fromString('thread-1')), InvalidFact::class],
        ] as [$slot, $fill, $exception]) {
            try {
                $campaign->fillFactSlot($this->id('fact-1'), $slot, $fill, $this->release);
                self::fail('Expected '.$exception);
            } catch (\DomainException $caught) {
                self::assertInstanceOf($exception, $caught);
            }
        }

        self::assertSame(['place-1'], array_map(static fn (\App\Play\Domain\Campaign\Lists\Place $place): string => $place->id()->toString(), $campaign->places()));
        self::assertSame([], $campaign->facts($this->release));
    }

    #[Test]
    public function aFreeFactLinksAPlaceAndAGhostPlaceIsRefused(): void
    {
        $campaign = $this->campaign();
        $campaign->addPlace(PlaceId::fromString('place-1'), 'Saltmarsh', null, [], [], 1, $this->release);

        $fact = $campaign->addFreeFact($this->id('fact-1'), 'Guild', 'Meets here', [FactLink::place(PlaceId::fromString('place-1'))], $this->release);

        self::assertSame(['place:place-1'], array_map(static fn (FactLink $link): string => $link->kind->value.':'.$link->id, $fact->links()));
        $this->expectException(UnknownPlace::class);
        $campaign->addFreeFact($this->id('fact-2'), 'Ghost', 'Text', [FactLink::place(PlaceId::fromString('ghost'))], $this->release);
    }

    #[Test]
    public function closingTheThreadOfAFactSlotClosesOnlyThatThreadAndNothingForOtherSlots(): void
    {
        $campaign = $this->campaign();
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Vault', [], [], 1, $this->release);
        $campaign->addThread(ThreadId::fromString('thread-2'), 'Ledger', [], [], 1, $this->release);
        $campaign->addPlace(PlaceId::fromString('place-1'), 'Saltmarsh', null, [], [], 1, $this->release);
        $campaign->fillFactSlot($this->id('fact-1'), 'goal', FactFill::existingThread(ThreadId::fromString('thread-1')), $this->release);
        $campaign->fillFactSlot($this->id('fact-2'), 'site', FactFill::existingPlace(PlaceId::fromString('place-1')), $this->release);

        foreach (['city', 'prince', 'site', 'nowhere'] as $slot) {
            $campaign->closeThreadOfFactSlot($slot);
        }

        self::assertSame([true, true], [$campaign->thread(ThreadId::fromString('thread-1'))?->isOpen(), $campaign->place(PlaceId::fromString('place-1'))?->isOpen()]);

        $campaign->closeThreadOfFactSlot('goal');
        $campaign->closeThreadOfFactSlot('goal');

        self::assertSame([false, true], [$campaign->thread(ThreadId::fromString('thread-1'))?->isOpen(), $campaign->thread(ThreadId::fromString('thread-2'))?->isOpen()]);
    }

    #[Test]
    public function refillingAFilledNpcOrThreadSlotByNameKeepsTheFactIdAndLinksAndCreatesTheItem(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $this->release);
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Vault', [], [], 1, $this->release);
        $campaign->addFreeFact($this->id('other'), 'Other', 'Text', [], $this->release);
        $campaign->fillFactSlot($this->id('fact-1'), 'prince', FactFill::existingNpc(NpcId::fromString('npc-1')), $this->release);
        $campaign->fillFactSlot($this->id('fact-2'), 'goal', FactFill::existingThread(ThreadId::fromString('thread-1')), $this->release);
        $links = [FactLink::fact($this->id('other'))];
        $campaign->editFact($this->id('fact-1'), null, null, $links, $this->release);
        $campaign->editFact($this->id('fact-2'), null, null, $links, $this->release);

        $prince = $campaign->fillFactSlot($this->id('ignored-1'), 'prince', FactFill::newNpc(NpcId::fromString('npc-2'), 'Valen'), $this->release);
        $goal = $campaign->fillFactSlot($this->id('ignored-2'), 'goal', FactFill::newThread(ThreadId::fromString('thread-2'), 'Find the heir'), $this->release);

        self::assertSame(['fact-1', 'npc', 'npc-2', ['fact:other']], [$prince->id()->toString(), $prince->subject()?->kind->value, $prince->subject()?->id, array_map(static fn (FactLink $link): string => $link->kind->value.':'.$link->id, $prince->links())]);
        self::assertSame(['fact-2', 'thread', 'thread-2', ['fact:other']], [$goal->id()->toString(), $goal->subject()?->kind->value, $goal->subject()?->id, array_map(static fn (FactLink $link): string => $link->kind->value.':'.$link->id, $goal->links())]);
        self::assertSame('Valen', $campaign->npc(NpcId::fromString('npc-2'))?->name());
        self::assertSame('Find the heir', $campaign->thread(ThreadId::fromString('thread-2'))?->name());
        self::assertNull($campaign->fact($this->id('ignored-1')));
        self::assertNull($campaign->fact($this->id('ignored-2')));
        self::assertCount(3, $campaign->facts($this->release));
    }

    #[Test]
    public function aSlotFilledByNameCreatesAnOpenWeightOneNpcOrThreadInTheSameCall(): void
    {
        $campaign = $this->campaign();

        $prince = $campaign->fillFactSlot($this->id('fact-1'), 'prince', FactFill::newNpc(NpcId::fromString('npc-1'), '  Valen  '), $this->release);
        $goal = $campaign->fillFactSlot($this->id('fact-2'), 'goal', FactFill::newThread(ThreadId::fromString('thread-1'), 'Find the heir'), $this->release);

        $npc = $campaign->npc(NpcId::fromString('npc-1'));
        $thread = $campaign->thread(ThreadId::fromString('thread-1'));
        self::assertSame(['Valen', null, [], [], 1, ItemStatus::Open], [$npc?->name(), $npc?->agenda(), $npc?->tags(), $npc?->freeTags(), $npc?->weight(), $npc?->status()]);
        self::assertSame(['Find the heir', [], [], 1, ItemStatus::Open], [$thread?->name(), $thread?->tags(), $thread?->freeTags(), $thread?->weight(), $thread?->status()]);
        self::assertSame(['npc-1', 'thread-1'], [$prince->subject()?->id, $goal->subject()?->id]);
    }

    #[Test]
    public function aRefusedFillThatWouldCreateAnNpcCreatesNothing(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $this->release);

        foreach ([
            ['prince', FactFill::newNpc(NpcId::fromString('npc-2'), ' '), InvalidNpc::class],
            ['prince', FactFill::newNpc(NpcId::fromString('npc-1'), 'Twin'), NpcAlreadyExists::class],
            ['city', FactFill::newNpc(NpcId::fromString('npc-2'), 'Valen'), InvalidFact::class],
            ['goal', FactFill::newNpc(NpcId::fromString('npc-2'), 'Valen'), InvalidFact::class],
            ['nowhere', FactFill::newNpc(NpcId::fromString('npc-2'), 'Valen'), UnknownFactSlot::class],
        ] as [$slot, $fill, $exception]) {
            try {
                $campaign->fillFactSlot($this->id('fact-1'), $slot, $fill, $this->release);
                self::fail('Expected '.$exception);
            } catch (\DomainException $caught) {
                self::assertInstanceOf($exception, $caught);
            }
        }

        self::assertCount(1, $campaign->npcs());
        self::assertSame([], $campaign->threads());
        self::assertSame([], $campaign->facts($this->release));
    }

    #[Test]
    public function aFillThatBreaksARuleLeavesAFilledSlotUntouched(): void
    {
        $campaign = $this->campaign();
        $filled = $campaign->fillFactSlot($this->id('fact-1'), 'city', FactFill::text('Ashford'), $this->release);

        foreach ([
            FactFill::text(' '),
            FactFill::text(str_repeat('a', 2001)),
            FactFill::existingNpc(NpcId::fromString('npc-1')),
        ] as $fill) {
            try {
                $campaign->fillFactSlot($this->id('fact-2'), 'city', $fill, $this->release);
                self::fail('Expected InvalidFact.');
            } catch (InvalidFact) {
            }
        }

        self::assertSame([$filled], $campaign->facts($this->release));
    }

    #[Test]
    public function aSlotValueThatDoesNotFitItsTypeOrNamesNothingIsRefused(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $this->release);
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Vault', [], [], 1, $this->release);

        foreach ([
            ['prince', FactFill::text('Valen'), InvalidFact::class],
            ['goal', FactFill::existingNpc(NpcId::fromString('npc-1')), InvalidFact::class],
            ['prince', FactFill::existingThread(ThreadId::fromString('thread-1')), InvalidFact::class],
            ['city', FactFill::existingThread(ThreadId::fromString('thread-1')), InvalidFact::class],
            ['prince', FactFill::existingNpc(NpcId::fromString('ghost')), UnknownNpc::class],
            ['goal', FactFill::existingThread(ThreadId::fromString('ghost')), UnknownThread::class],
            ['nowhere', FactFill::text('x'), UnknownFactSlot::class],
        ] as [$slot, $fill, $exception]) {
            try {
                $campaign->fillFactSlot($this->id('fact-1'), $slot, $fill, $this->release);
                self::fail('Expected '.$exception);
            } catch (\DomainException $caught) {
                self::assertInstanceOf($exception, $caught);
            }
        }

        self::assertSame([], $campaign->facts($this->release));
    }

    #[Test]
    public function aSlotFactIdAlreadyHeldByAnotherFactIsRefused(): void
    {
        $campaign = $this->campaign();
        $campaign->addFreeFact($this->id('fact-1'), 'Free', 'Text', [], $this->release);

        $this->expectException(FactAlreadyExists::class);

        $campaign->fillFactSlot($this->id('fact-1'), 'city', FactFill::text('Ashford'), $this->release);
    }

    #[Test]
    public function aFreeFactEditsItsLabelTextAndLinksAndNullKeepsTheCurrentValue(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $this->release);
        $campaign->addFreeFact($this->id('fact-1'), 'Guild', 'Old text', [], $this->release);

        $labelOnly = $campaign->editFact($this->id('fact-1'), ' The Guild ', null, [FactLink::npc(NpcId::fromString('npc-1'))], $this->release);
        $textOnly = $campaign->editFact($this->id('fact-1'), null, 'New text', [], $this->release);

        self::assertSame(['The Guild', 'Old text', ['npc:npc-1']], [$labelOnly->label(), $labelOnly->text(), array_map(static fn (FactLink $link): string => $link->kind->value.':'.$link->id, $labelOnly->links())]);
        self::assertSame(['The Guild', 'New text', []], [$textOnly->label(), $textOnly->text(), $textOnly->links()]);
        self::assertSame($textOnly, $campaign->fact($this->id('fact-1')));
    }

    #[Test]
    public function aSlotFactEditsOnlyItsLinks(): void
    {
        $campaign = $this->campaign();
        $campaign->addFreeFact($this->id('other'), 'Other', 'Text', [], $this->release);
        $campaign->fillFactSlot($this->id('fact-1'), 'city', FactFill::text('Ashford'), $this->release);

        $edited = $campaign->editFact($this->id('fact-1'), null, null, [FactLink::fact($this->id('other'))], $this->release);

        self::assertSame(['Ashford', ['other']], [$edited->text(), array_map(static fn (FactLink $link): string => $link->id, $edited->links())]);

        foreach ([['Label', null], [null, 'Text'], ['Label', 'Text']] as [$label, $text]) {
            try {
                $campaign->editFact($this->id('fact-1'), $label, $text, [], $this->release);
                self::fail('Expected InvalidFact.');
            } catch (InvalidFact) {
            }
        }

        self::assertSame($edited, $campaign->fact($this->id('fact-1')));
    }

    #[Test]
    public function anEditBreakingARuleOrNamingAnUnknownThingChangesNothing(): void
    {
        $campaign = $this->campaign();
        $fact = $campaign->addFreeFact($this->id('fact-1'), 'Guild', 'Text', [], $this->release);

        foreach ([
            [' ', null, [], InvalidFact::class],
            [null, str_repeat('a', 2001), [], InvalidFact::class],
            [null, null, [FactLink::fact($this->id('fact-1'))], InvalidFact::class],
            [null, null, [FactLink::fact($this->id('x')), FactLink::fact($this->id('x'))], InvalidFact::class],
            [null, null, [FactLink::npc(NpcId::fromString('ghost'))], UnknownNpc::class],
            [null, null, [FactLink::thread(ThreadId::fromString('ghost'))], UnknownThread::class],
            [null, null, [FactLink::fact($this->id('ghost'))], UnknownFact::class],
        ] as [$label, $text, $links, $exception]) {
            try {
                $campaign->editFact($this->id('fact-1'), $label, $text, $links, $this->release);
                self::fail('Expected '.$exception);
            } catch (\DomainException $caught) {
                self::assertInstanceOf($exception, $caught);
            }
        }

        self::assertSame([$fact], $campaign->facts($this->release));
    }

    #[Test]
    public function editingAnUnknownFactIsRefused(): void
    {
        $this->expectException(UnknownFact::class);

        $this->campaign()->editFact($this->id('ghost'), 'Label', 'Text', [], $this->release);
    }

    #[Test]
    public function slotFactsListFirstInReleaseSlotOrderThenFreeFactsInCreationOrder(): void
    {
        $campaign = $this->campaign();
        $campaign->addFreeFact($this->id('free-1'), 'First', 'Text', [], $this->release);
        $campaign->fillFactSlot($this->id('goal-fact'), 'goal', FactFill::newThread(ThreadId::fromString('thread-1'), 'Vault'), $this->release);
        $campaign->addFreeFact($this->id('free-2'), 'Second', 'Text', [], $this->release);
        $campaign->fillFactSlot($this->id('city-fact'), 'city', FactFill::text('Ashford'), $this->release);

        self::assertSame(['city-fact', 'goal-fact', 'free-1', 'free-2'], array_map(static fn (Fact $fact): string => $fact->id()->toString(), $campaign->facts($this->release)));
    }

    #[Test]
    public function aCommandWithAReleaseOtherThanThePinnedOneIsRefused(): void
    {
        $other = new GameSystemSnapshot('other', 'Other', 1, null, [], []);

        $this->expectException(\LogicException::class);

        $this->campaign()->addFreeFact($this->id('fact-1'), 'Label', 'Text', [], $other);
    }

    #[Test]
    public function reconstituteKeepsTheFactsAndChecksThem(): void
    {
        $npc = Npc::of(NpcId::fromString('npc-1'), 'Mira', null, [], []);
        $free = Fact::of($this->id('fact-1'), null, 'Guild', 'Text', null, [FactLink::npc($npc->id())]);
        $slot = Fact::of($this->id('fact-2'), 'prince', null, null, FactLink::npc($npc->id()), [FactLink::fact($this->id('fact-1'))]);

        $campaign = $this->reconstituted([$npc], [$free, $slot]);

        self::assertSame([$slot, $free], $campaign->facts($this->release));
    }

    /**
     * @return iterable<string, array{class-string<\DomainException>, list<Fact>}>
     */
    public static function inconsistentStoredFacts(): iterable
    {
        $id = static fn (string $value): FactId => FactId::fromString($value);
        $free = self::freeFact(...);

        yield 'duplicate id' => [FactAlreadyExists::class, [$free('fact-1'), $free('fact-1')]];
        yield 'two facts for one slot' => [InvalidFact::class, [Fact::of($id('fact-1'), 'city', null, 'A', null, []), Fact::of($id('fact-2'), 'city', null, 'B', null, [])]];
        yield 'link to an unknown NPC' => [UnknownNpc::class, [$free('fact-1', [FactLink::npc(NpcId::fromString('ghost'))])]];
        yield 'link to an unknown Thread' => [UnknownThread::class, [$free('fact-1', [FactLink::thread(ThreadId::fromString('ghost'))])]];
        yield 'link to an unknown Place' => [UnknownPlace::class, [$free('fact-1', [FactLink::place(PlaceId::fromString('ghost'))])]];
        yield 'link to an unknown fact' => [UnknownFact::class, [$free('fact-1', [FactLink::fact($id('ghost'))])]];
        yield 'subject that is an unknown NPC' => [UnknownNpc::class, [Fact::of($id('fact-1'), 'prince', null, null, FactLink::npc(NpcId::fromString('ghost')), [])]];
        yield 'subject that is an unknown Thread' => [UnknownThread::class, [Fact::of($id('fact-1'), 'goal', null, null, FactLink::thread(ThreadId::fromString('ghost')), [])]];
        yield 'subject that is an unknown Place' => [UnknownPlace::class, [Fact::of($id('fact-1'), 'site', null, null, FactLink::place(PlaceId::fromString('ghost')), [])]];
        yield 'over the limit' => [CampaignLimitReached::class, array_map(static fn (int $i): Fact => $free('fact-'.$i), range(1, Campaign::MAX_FACTS + 1))];
    }

    /**
     * @param class-string<\DomainException> $exception
     * @param list<Fact>                     $facts
     */
    #[Test]
    #[DataProvider('inconsistentStoredFacts')]
    public function reconstituteRefusesFactsThatBreakAnInvariant(string $exception, array $facts): void
    {
        $this->expectException($exception);

        $this->reconstituted([], $facts);
    }

    #[Test]
    public function aFactRefusesAnInconsistentSlotAndValueCombination(): void
    {
        foreach ([
            [null, 'L', 'T', FactLink::npc(NpcId::fromString('n'))],
            [null, null, 'T', null],
            [null, 'L', null, null],
            ['city', 'L', 'T', null],
            ['city', null, null, null],
            ['city', null, 'T', FactLink::npc(NpcId::fromString('n'))],
            ['prince', null, null, FactLink::fact($this->id('f'))],
            [' ', null, 'T', null],
        ] as [$slot, $label, $text, $subject]) {
            try {
                Fact::of($this->id('fact-1'), $slot, $label, $text, $subject, []);
                self::fail('Expected InvalidFact.');
            } catch (InvalidFact) {
            }
        }

        $this->expectException(InvalidFact::class);

        FactId::fromString(' ');
    }

    #[Test]
    public function aLinkMustNameItsTarget(): void
    {
        $this->expectException(InvalidFact::class);

        FactLink::of(FactLinkKind::Npc, ' ');
    }

    /**
     * @param list<Npc>  $npcs
     * @param list<Fact> $facts
     */
    private function reconstituted(array $npcs, array $facts): Campaign
    {
        return Campaign::reconstitute(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00'), [], npcs: $npcs, facts: $facts);
    }

    /**
     * @param list<FactLink> $links
     */
    private static function freeFact(string $id, array $links = []): Fact
    {
        return Fact::of(FactId::fromString($id), null, 'Label', 'Text', null, $links);
    }

    private function id(string $value): FactId
    {
        return FactId::fromString($value);
    }

    private function campaign(): Campaign
    {
        return Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00'));
    }
}
