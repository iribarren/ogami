<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\AddThread;
use App\Play\Application\AddThreadHandler;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\CloseThread;
use App\Play\Application\CloseThreadHandler;
use App\Play\Application\EditThread;
use App\Play\Application\EditThreadHandler;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\ReopenThread;
use App\Play\Application\ReopenThreadHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\Lists\InvalidThread;
use App\Play\Domain\Campaign\Lists\ItemStatus;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\Campaign\Lists\ThreadAlreadyExists;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\Campaign\Lists\UnknownThread;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Tag;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\Snapshots;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddThread::class)]
#[CoversClass(AddThreadHandler::class)]
#[CoversClass(EditThread::class)]
#[CoversClass(EditThreadHandler::class)]
#[CoversClass(CloseThread::class)]
#[CoversClass(CloseThreadHandler::class)]
#[CoversClass(ReopenThread::class)]
#[CoversClass(ReopenThreadHandler::class)]
final class ThreadHandlersTest extends TestCase
{
    private InMemoryCampaignRepository $campaigns;
    private AddThreadHandler $add;
    private EditThreadHandler $edit;
    private CloseThreadHandler $close;
    private ReopenThreadHandler $reopen;

    protected function setUp(): void
    {
        $releases = new InMemoryPublishedGameSystemReleases();
        $releases->add(Snapshots::withTags('heist', 'Heist', 1));
        // A newer release with other Tags: the commands follow the pinned v1.
        $releases->add(new GameSystemSnapshot('heist', 'Heist, revised', 2, null, [], [], tags: [new Tag('boss', 'Boss')]));
        $this->campaigns = new InMemoryCampaignRepository();
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00')));
        $owned = new OwnedCampaigns($this->campaigns);
        $this->add = new AddThreadHandler($owned, $this->campaigns, $releases);
        $this->edit = new EditThreadHandler($owned, $this->campaigns, $releases);
        $this->close = new CloseThreadHandler($owned, $this->campaigns);
        $this->reopen = new ReopenThreadHandler($owned, $this->campaigns);
    }

    #[Test]
    public function itAddsAnOpenThreadAndChecksItsTagsAgainstThePinnedRelease(): void
    {
        ($this->add)(new AddThread('campaign-1', 'user-1', 'thread-1', ' Rob the vault ', ['rival'], ['Fence'], 2));

        $thread = $this->thread('thread-1');
        self::assertSame(['Rob the vault', ['rival'], ['Fence'], 2, ItemStatus::Open], [$thread?->name(), $thread?->tags(), $thread?->freeTags(), $thread?->weight(), $thread?->status()]);
    }

    #[Test]
    public function aTagOfAnotherReleaseThanThePinnedOneIsRefusedAndNothingChanges(): void
    {
        $this->expectException(UnknownReleaseTag::class);

        try {
            ($this->add)(new AddThread('campaign-1', 'user-1', 'thread-1', 'Rob the vault', ['boss'], [], 1));
        } finally {
            self::assertSame([], $this->campaign()->threads());
        }
    }

    #[Test]
    public function anInvalidThreadIsRefusedAndNothingChanges(): void
    {
        $this->expectException(InvalidThread::class);

        try {
            ($this->add)(new AddThread('campaign-1', 'user-1', 'thread-1', 'Rob the vault', [], [], 4));
        } finally {
            self::assertSame([], $this->campaign()->threads());
        }
    }

    #[Test]
    public function aRepeatedIdIsRefusedAndTheThreadKept(): void
    {
        ($this->add)(new AddThread('campaign-1', 'user-1', 'thread-1', 'Rob the vault', [], [], 1));

        try {
            ($this->add)(new AddThread('campaign-1', 'user-1', 'thread-1', 'Other', [], [], 1));
            self::fail('The repeated id was accepted.');
        } catch (ThreadAlreadyExists) {
            self::assertSame(['Rob the vault'], array_map(static fn (Thread $thread): string => $thread->name(), $this->campaign()->threads()));
        }
    }

    #[Test]
    public function aFullCampaignRefusesAnotherThread(): void
    {
        $campaign = $this->campaign();
        for ($i = 1; $i <= Campaign::MAX_THREADS; ++$i) {
            $campaign->addThread(ThreadId::fromString('thread-'.$i), 'Thread '.$i, [], [], 1, Snapshots::withTags('heist', 'Heist', 1));
        }

        $this->campaigns->save($campaign);

        $this->expectException(CampaignLimitReached::class);

        ($this->add)(new AddThread('campaign-1', 'user-1', 'thread-501', 'One too many', [], [], 1));
    }

    #[Test]
    public function itReplacesTheEditableFieldsAndKeepsTheStatus(): void
    {
        ($this->add)(new AddThread('campaign-1', 'user-1', 'thread-1', 'Rob the vault', ['rival'], ['Fence'], 2));
        ($this->close)(new CloseThread('campaign-1', 'user-1', 'thread-1'));

        ($this->edit)(new EditThread('campaign-1', 'user-1', 'thread-1', 'Rob the bank', ['ally'], [], 1));

        $thread = $this->thread('thread-1');
        self::assertSame(['Rob the bank', ['ally'], [], 1, ItemStatus::Closed], [$thread?->name(), $thread?->tags(), $thread?->freeTags(), $thread?->weight(), $thread?->status()]);
    }

    #[Test]
    public function aFailingEditChangesNothing(): void
    {
        ($this->add)(new AddThread('campaign-1', 'user-1', 'thread-1', 'Rob the vault', ['rival'], [], 2));
        $before = $this->thread('thread-1');

        foreach ([
            new EditThread('campaign-1', 'user-1', 'thread-1', 'Renamed', ['boss'], [], 1),
            new EditThread('campaign-1', 'user-1', 'thread-1', 'Renamed', [], [], 0),
        ] as $command) {
            try {
                ($this->edit)($command);
                self::fail('The failing edit was accepted.');
            } catch (InvalidThread|UnknownReleaseTag) {
            }
        }

        self::assertEquals($before, $this->thread('thread-1'));
    }

    #[Test]
    public function itClosesAndReopensAnThreadAndBothAreIdempotent(): void
    {
        ($this->add)(new AddThread('campaign-1', 'user-1', 'thread-1', 'Rob the vault', [], [], 1));

        ($this->close)(new CloseThread('campaign-1', 'user-1', 'thread-1'));
        ($this->close)(new CloseThread('campaign-1', 'user-1', 'thread-1'));
        self::assertSame(ItemStatus::Closed, $this->thread('thread-1')?->status());

        ($this->reopen)(new ReopenThread('campaign-1', 'user-1', 'thread-1'));
        ($this->reopen)(new ReopenThread('campaign-1', 'user-1', 'thread-1'));
        self::assertSame(ItemStatus::Open, $this->thread('thread-1')->status());
    }

    #[Test]
    public function anUnknownThreadIsRefusedByEveryCommandThatNamesOne(): void
    {
        foreach ([
            fn () => ($this->edit)(new EditThread('campaign-1', 'user-1', 'missing', 'Rob the vault', [], [], 1)),
            fn () => ($this->close)(new CloseThread('campaign-1', 'user-1', 'missing')),
            fn () => ($this->reopen)(new ReopenThread('campaign-1', 'user-1', 'missing')),
        ] as $command) {
            try {
                $command();
                self::fail('The unknown Thread was accepted.');
            } catch (UnknownThread $exception) {
                self::assertSame('Thread "missing" not found.', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFoundByEveryCommand(): void
    {
        ($this->add)(new AddThread('campaign-1', 'user-1', 'thread-1', 'Rob the vault', [], [], 1));

        foreach ([
            fn () => ($this->add)(new AddThread('campaign-1', 'user-2', 'thread-2', 'Brann', [], [], 1)),
            fn () => ($this->edit)(new EditThread('campaign-1', 'user-2', 'thread-1', 'Renamed', [], [], 1)),
            fn () => ($this->close)(new CloseThread('campaign-1', 'user-2', 'thread-1')),
            fn () => ($this->reopen)(new ReopenThread('campaign-1', 'user-2', 'thread-1')),
        ] as $command) {
            try {
                $command();
                self::fail('Another player\'s campaign was changed.');
            } catch (CampaignNotFound) {
            }
        }

        self::assertEquals(Thread::of(ThreadId::fromString('thread-1'), 'Rob the vault', [], [], 1), $this->thread('thread-1'));
    }

    private function campaign(): Campaign
    {
        return $this->campaigns->ofId(CampaignId::fromString('campaign-1')) ?? throw new \LogicException('The campaign is stored.');
    }

    private function thread(string $id): ?Thread
    {
        return $this->campaign()->thread(ThreadId::fromString($id));
    }
}
