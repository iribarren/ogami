<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\AddFact;
use App\Play\Application\AddFactHandler;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\EditFact;
use App\Play\Application\EditFactHandler;
use App\Play\Application\OwnedCampaigns;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactAlreadyExists;
use App\Play\Domain\Campaign\Facts\FactFill;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\Facts\InvalidFact;
use App\Play\Domain\Campaign\Facts\UnknownFact;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\Lists\UnknownThread;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\FactSlotType;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\UnreadablePublishedGameSystemReleases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddFact::class)]
#[CoversClass(AddFactHandler::class)]
#[CoversClass(EditFact::class)]
#[CoversClass(EditFactHandler::class)]
final class FactHandlersTest extends TestCase
{
    private GameSystemSnapshot $release;
    private InMemoryCampaignRepository $campaigns;
    private AddFactHandler $add;
    private EditFactHandler $edit;

    protected function setUp(): void
    {
        $this->release = new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], factSlots: [new FactSlot('city', 'City', FactSlotType::Text)]);
        $releases = new InMemoryPublishedGameSystemReleases();
        $releases->add($this->release);
        $this->campaigns = new InMemoryCampaignRepository();
        $campaign = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00'));
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $this->release);
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Vault', [], [], 1, $this->release);
        $campaign->fillFactSlot(FactId::fromString('city-1'), 'city', FactFill::text('Ashford'), $this->release);
        $this->campaigns->add($campaign);
        $owned = new OwnedCampaigns($this->campaigns);
        $this->add = new AddFactHandler($owned, $this->campaigns, $releases);
        $this->edit = new EditFactHandler($owned, $this->campaigns, $releases);
    }

    #[Test]
    public function itAddsAFreeFactWithTrimmedValuesAndItsLinks(): void
    {
        ($this->add)(new AddFact('campaign-1', 'user-1', 'fact-1', '  Guild ', ' Owes a debt ', [['kind' => 'npc', 'id' => 'npc-1'], ['kind' => 'thread', 'id' => 'thread-1'], ['kind' => 'fact', 'id' => 'city-1']]));

        $fact = $this->fact('fact-1');
        self::assertSame(['Guild', 'Owes a debt', ['npc:npc-1', 'thread:thread-1', 'fact:city-1']], [$fact->label(), $fact->text(), array_map(static fn (FactLink $link): string => $link->kind->value.':'.$link->id, $fact->links())]);
    }

    #[Test]
    public function aRefusedAddChangesNothing(): void
    {
        foreach ([
            [new AddFact('campaign-1', 'user-1', 'fact-1', ' ', 'Text', []), InvalidFact::class],
            [new AddFact('campaign-1', 'user-1', 'fact-1', 'Label', 'Text', [['kind' => 'npc', 'id' => 'npc-1'], ['kind' => 'npc', 'id' => 'npc-1']]), InvalidFact::class],
            [new AddFact('campaign-1', 'user-1', 'fact-1', 'Label', 'Text', [['kind' => 'npc', 'id' => 'missing']]), UnknownNpc::class],
            [new AddFact('campaign-1', 'user-1', 'fact-1', 'Label', 'Text', [['kind' => 'thread', 'id' => 'missing']]), UnknownThread::class],
            [new AddFact('campaign-1', 'user-1', 'fact-1', 'Label', 'Text', [['kind' => 'fact', 'id' => 'missing']]), UnknownFact::class],
            [new AddFact('campaign-1', 'user-1', 'city-1', 'Label', 'Text', []), FactAlreadyExists::class],
        ] as [$command, $error]) {
            try {
                ($this->add)($command);
                self::fail('The refused add was accepted.');
            } catch (\Throwable $exception) {
                self::assertInstanceOf($error, $exception);
            }
        }

        self::assertCount(1, $this->campaign()->facts($this->release));
    }

    #[Test]
    public function aFullCampaignRefusesAnotherFact(): void
    {
        $campaign = $this->campaign();
        for ($i = 2; $i <= Campaign::MAX_FACTS; ++$i) {
            $campaign->addFreeFact(FactId::fromString('fact-'.$i), 'Fact '.$i, 'Text', [], $this->release);
        }

        $this->campaigns->save($campaign);

        $this->expectException(CampaignLimitReached::class);

        ($this->add)(new AddFact('campaign-1', 'user-1', 'fact-501', 'One too many', 'Text', []));
    }

    #[Test]
    public function itReplacesTheLabelTextAndLinksOfAFreeFact(): void
    {
        ($this->add)(new AddFact('campaign-1', 'user-1', 'fact-1', 'Guild', 'Owes a debt', [['kind' => 'npc', 'id' => 'npc-1']]));

        ($this->edit)(new EditFact('campaign-1', 'user-1', 'fact-1', 'Thieves guild', 'Owes two debts', [['kind' => 'thread', 'id' => 'thread-1']]));

        $fact = $this->fact('fact-1');
        self::assertSame(['Thieves guild', 'Owes two debts', ['thread:thread-1']], [$fact->label(), $fact->text(), array_map(static fn (FactLink $link): string => $link->kind->value.':'.$link->id, $fact->links())]);
    }

    #[Test]
    public function aFreeFactNeedsBothItsLabelAndItsTextToBeEdited(): void
    {
        ($this->add)(new AddFact('campaign-1', 'user-1', 'fact-1', 'Guild', 'Owes a debt', []));
        $before = $this->fact('fact-1');

        foreach ([
            new EditFact('campaign-1', 'user-1', 'fact-1', null, 'Text', []),
            new EditFact('campaign-1', 'user-1', 'fact-1', 'Label', null, []),
            new EditFact('campaign-1', 'user-1', 'fact-1', null, null, [['kind' => 'npc', 'id' => 'npc-1']]),
        ] as $command) {
            try {
                ($this->edit)($command);
                self::fail('The incomplete edit was accepted.');
            } catch (InvalidFact $exception) {
                self::assertSame('A free fact needs both its label and its text.', $exception->getMessage());
            }
        }

        self::assertEquals($before, $this->fact('fact-1'));
    }

    #[Test]
    public function aSlotFactTakesOnlyNewLinks(): void
    {
        ($this->edit)(new EditFact('campaign-1', 'user-1', 'city-1', null, null, [['kind' => 'npc', 'id' => 'npc-1']]));

        $fact = $this->fact('city-1');
        self::assertSame(['Ashford', ['npc:npc-1']], [$fact->text(), array_map(static fn (FactLink $link): string => $link->kind->value.':'.$link->id, $fact->links())]);

        $before = $fact;
        foreach ([['Label', null], [null, 'Text'], ['Label', 'Text']] as [$label, $text]) {
            try {
                ($this->edit)(new EditFact('campaign-1', 'user-1', 'city-1', $label, $text, []));
                self::fail('The slot fact label or text was accepted.');
            } catch (InvalidFact) {
            }
        }

        self::assertEquals($before, $this->fact('city-1'));
    }

    #[Test]
    public function aFailingEditChangesNothing(): void
    {
        ($this->add)(new AddFact('campaign-1', 'user-1', 'fact-1', 'Guild', 'Owes a debt', [['kind' => 'npc', 'id' => 'npc-1']]));
        $before = $this->fact('fact-1');

        foreach ([
            [new EditFact('campaign-1', 'user-1', 'fact-1', ' ', 'Text', []), InvalidFact::class],
            [new EditFact('campaign-1', 'user-1', 'fact-1', 'Label', 'Text', [['kind' => 'fact', 'id' => 'fact-1']]), InvalidFact::class],
            [new EditFact('campaign-1', 'user-1', 'fact-1', 'Label', 'Text', [['kind' => 'thread', 'id' => 'missing']]), UnknownThread::class],
            [new EditFact('campaign-1', 'user-1', 'fact-1', 'Label', 'Text', [['kind' => 'fact', 'id' => 'missing']]), UnknownFact::class],
        ] as [$command, $error]) {
            try {
                ($this->edit)($command);
                self::fail('The failing edit was accepted.');
            } catch (\Throwable $exception) {
                self::assertInstanceOf($error, $exception);
            }
        }

        self::assertEquals($before, $this->fact('fact-1'));
    }

    #[Test]
    public function anUnknownFactIsRefusedBeforeAnUnreadableReleaseIsRead(): void
    {
        $handler = new EditFactHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, new UnreadablePublishedGameSystemReleases(GameSystemReleaseNotFound::for('heist', 1)));

        try {
            $handler(new EditFact('campaign-1', 'user-1', 'missing', 'Label', 'Text', []));
            self::fail('The unknown fact was accepted.');
        } catch (UnknownFact $exception) {
            self::assertSame('Fact "missing" not found.', $exception->getMessage());
        }

        $this->expectException(InvalidFact::class);
        $handler(new EditFact('campaign-1', 'user-1', ' ', 'Label', 'Text', []));
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFoundByBothCommands(): void
    {
        foreach ([
            fn () => ($this->add)(new AddFact('campaign-1', 'user-2', 'fact-1', 'Label', 'Text', [])),
            fn () => ($this->edit)(new EditFact('campaign-1', 'user-2', 'city-1', null, null, [])),
        ] as $command) {
            try {
                $command();
                self::fail('Another player\'s campaign was changed.');
            } catch (CampaignNotFound) {
            }
        }

        self::assertCount(1, $this->campaign()->facts($this->release));
    }

    private function campaign(): Campaign
    {
        return $this->campaigns->ofId(CampaignId::fromString('campaign-1')) ?? throw new \LogicException('The campaign is stored.');
    }

    private function fact(string $id): Fact
    {
        return $this->campaign()->fact(FactId::fromString($id)) ?? throw new \LogicException('The fact is stored.');
    }
}
