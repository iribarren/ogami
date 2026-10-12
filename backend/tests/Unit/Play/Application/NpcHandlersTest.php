<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\AddNpc;
use App\Play\Application\AddNpcHandler;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\CloseNpc;
use App\Play\Application\CloseNpcHandler;
use App\Play\Application\EditNpc;
use App\Play\Application\EditNpcHandler;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\ReopenNpc;
use App\Play\Application\ReopenNpcHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\ItemStatus;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcAlreadyExists;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Tag;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\Snapshots;
use App\Tests\Support\Play\UnreadablePublishedGameSystemReleases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddNpc::class)]
#[CoversClass(AddNpcHandler::class)]
#[CoversClass(EditNpc::class)]
#[CoversClass(EditNpcHandler::class)]
#[CoversClass(CloseNpc::class)]
#[CoversClass(CloseNpcHandler::class)]
#[CoversClass(ReopenNpc::class)]
#[CoversClass(ReopenNpcHandler::class)]
final class NpcHandlersTest extends TestCase
{
    private InMemoryCampaignRepository $campaigns;
    private AddNpcHandler $add;
    private EditNpcHandler $edit;
    private CloseNpcHandler $close;
    private ReopenNpcHandler $reopen;

    protected function setUp(): void
    {
        $releases = new InMemoryPublishedGameSystemReleases();
        $releases->add(Snapshots::withTags('heist', 'Heist', 1));
        // A newer release with other Tags: the commands follow the pinned v1.
        $releases->add(new GameSystemSnapshot('heist', 'Heist, revised', 2, null, [], [], tags: [new Tag('boss', 'Boss')]));
        $this->campaigns = new InMemoryCampaignRepository();
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00')));
        $owned = new OwnedCampaigns($this->campaigns);
        $this->add = new AddNpcHandler($owned, $this->campaigns, $releases);
        $this->edit = new EditNpcHandler($owned, $this->campaigns, $releases);
        $this->close = new CloseNpcHandler($owned, $this->campaigns);
        $this->reopen = new ReopenNpcHandler($owned, $this->campaigns);
    }

    #[Test]
    public function itAddsAnOpenNpcAndChecksItsTagsAgainstThePinnedRelease(): void
    {
        ($this->add)(new AddNpc('campaign-1', 'user-1', 'npc-1', ' Mira ', 'Wants the ledger', ['rival'], ['Fence'], 2));

        $npc = $this->npc('npc-1');
        self::assertSame(['Mira', 'Wants the ledger', ['rival'], ['Fence'], 2, ItemStatus::Open], [$npc?->name(), $npc?->agenda(), $npc?->tags(), $npc?->freeTags(), $npc?->weight(), $npc?->status()]);
    }

    #[Test]
    public function aTagOfAnotherReleaseThanThePinnedOneIsRefusedAndNothingChanges(): void
    {
        $this->expectException(UnknownReleaseTag::class);

        try {
            ($this->add)(new AddNpc('campaign-1', 'user-1', 'npc-1', 'Mira', null, ['boss'], [], 1));
        } finally {
            self::assertSame([], $this->campaign()->npcs());
        }
    }

    #[Test]
    public function anInvalidNpcIsRefusedAndNothingChanges(): void
    {
        $this->expectException(InvalidNpc::class);

        try {
            ($this->add)(new AddNpc('campaign-1', 'user-1', 'npc-1', 'Mira', null, [], [], 4));
        } finally {
            self::assertSame([], $this->campaign()->npcs());
        }
    }

    #[Test]
    public function aRepeatedIdIsRefusedAndTheNpcKept(): void
    {
        ($this->add)(new AddNpc('campaign-1', 'user-1', 'npc-1', 'Mira', null, [], [], 1));

        try {
            ($this->add)(new AddNpc('campaign-1', 'user-1', 'npc-1', 'Other', null, [], [], 1));
            self::fail('The repeated id was accepted.');
        } catch (NpcAlreadyExists) {
            self::assertSame(['Mira'], array_map(static fn (Npc $npc): string => $npc->name(), $this->campaign()->npcs()));
        }
    }

    #[Test]
    public function aFullCampaignRefusesAnotherNpc(): void
    {
        $campaign = $this->campaign();
        for ($i = 1; $i <= Campaign::MAX_NPCS; ++$i) {
            $campaign->addNpc(NpcId::fromString('npc-'.$i), 'NPC '.$i, null, [], [], 1, Snapshots::withTags('heist', 'Heist', 1));
        }

        $this->campaigns->save($campaign);

        $this->expectException(CampaignLimitReached::class);

        ($this->add)(new AddNpc('campaign-1', 'user-1', 'npc-501', 'One too many', null, [], [], 1));
    }

    #[Test]
    public function itReplacesTheEditableFieldsAndKeepsTheStatus(): void
    {
        ($this->add)(new AddNpc('campaign-1', 'user-1', 'npc-1', 'Mira', 'Wants the ledger', ['rival'], ['Fence'], 2));
        ($this->close)(new CloseNpc('campaign-1', 'user-1', 'npc-1'));

        ($this->edit)(new EditNpc('campaign-1', 'user-1', 'npc-1', 'Mira Vane', null, ['ally'], [], 1));

        $npc = $this->npc('npc-1');
        self::assertSame(['Mira Vane', null, ['ally'], [], 1, ItemStatus::Closed], [$npc?->name(), $npc?->agenda(), $npc?->tags(), $npc?->freeTags(), $npc?->weight(), $npc?->status()]);
    }

    #[Test]
    public function aFailingEditChangesNothing(): void
    {
        ($this->add)(new AddNpc('campaign-1', 'user-1', 'npc-1', 'Mira', null, ['rival'], [], 2));
        $before = $this->npc('npc-1');

        foreach ([
            new EditNpc('campaign-1', 'user-1', 'npc-1', 'Renamed', null, ['boss'], [], 1),
            new EditNpc('campaign-1', 'user-1', 'npc-1', 'Renamed', null, [], [], 0),
        ] as $command) {
            try {
                ($this->edit)($command);
                self::fail('The failing edit was accepted.');
            } catch (InvalidNpc|UnknownReleaseTag) {
            }
        }

        self::assertEquals($before, $this->npc('npc-1'));
    }

    #[Test]
    public function itClosesAndReopensAnNpcAndBothAreIdempotent(): void
    {
        ($this->add)(new AddNpc('campaign-1', 'user-1', 'npc-1', 'Mira', null, [], [], 1));

        ($this->close)(new CloseNpc('campaign-1', 'user-1', 'npc-1'));
        ($this->close)(new CloseNpc('campaign-1', 'user-1', 'npc-1'));
        self::assertSame(ItemStatus::Closed, $this->npc('npc-1')?->status());

        ($this->reopen)(new ReopenNpc('campaign-1', 'user-1', 'npc-1'));
        ($this->reopen)(new ReopenNpc('campaign-1', 'user-1', 'npc-1'));
        self::assertSame(ItemStatus::Open, $this->npc('npc-1')->status());
    }

    #[Test]
    public function anUnknownNpcIsRefusedByEveryCommandThatNamesOne(): void
    {
        foreach ([
            fn () => ($this->edit)(new EditNpc('campaign-1', 'user-1', 'missing', 'Mira', null, [], [], 1)),
            fn () => ($this->close)(new CloseNpc('campaign-1', 'user-1', 'missing')),
            fn () => ($this->reopen)(new ReopenNpc('campaign-1', 'user-1', 'missing')),
        ] as $command) {
            try {
                $command();
                self::fail('The unknown NPC was accepted.');
            } catch (UnknownNpc $exception) {
                self::assertSame('NPC "missing" not found.', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function anUnknownOrBlankNpcIdIsRefusedBeforeAnUnreadableReleaseIsRead(): void
    {
        $handler = new EditNpcHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, new UnreadablePublishedGameSystemReleases(GameSystemReleaseNotFound::for('heist', 1)));

        try {
            $handler(new EditNpc('campaign-1', 'user-1', 'missing', 'Mira', null, [], [], 1));
            self::fail('The unknown NPC was accepted.');
        } catch (UnknownNpc) {
        }

        $this->expectException(InvalidNpc::class);
        $handler(new EditNpc('campaign-1', 'user-1', ' ', 'Mira', null, [], [], 1));
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFoundByEveryCommand(): void
    {
        ($this->add)(new AddNpc('campaign-1', 'user-1', 'npc-1', 'Mira', null, [], [], 1));

        foreach ([
            fn () => ($this->add)(new AddNpc('campaign-1', 'user-2', 'npc-2', 'Brann', null, [], [], 1)),
            fn () => ($this->edit)(new EditNpc('campaign-1', 'user-2', 'npc-1', 'Renamed', null, [], [], 1)),
            fn () => ($this->close)(new CloseNpc('campaign-1', 'user-2', 'npc-1')),
            fn () => ($this->reopen)(new ReopenNpc('campaign-1', 'user-2', 'npc-1')),
        ] as $command) {
            try {
                $command();
                self::fail('Another player\'s campaign was changed.');
            } catch (CampaignNotFound) {
            }
        }

        self::assertEquals(Npc::of(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1), $this->npc('npc-1'));
    }

    private function campaign(): Campaign
    {
        return $this->campaigns->ofId(CampaignId::fromString('campaign-1')) ?? throw new \LogicException('The campaign is stored.');
    }

    private function npc(string $id): ?Npc
    {
        return $this->campaign()->npc(NpcId::fromString($id));
    }
}
