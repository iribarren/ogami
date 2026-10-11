<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\InvalidThread;
use App\Play\Domain\Campaign\Lists\ItemRules;
use App\Play\Domain\Campaign\Lists\ItemStatus;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcAlreadyExists;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\Thread;
use App\Play\Domain\Campaign\Lists\ThreadAlreadyExists;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\Campaign\Lists\UnknownThread;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A campaign keeps the player's NPCs and Threads: every rule fails closed, every command is all
 * or nothing, and open items list first.
 */
#[CoversClass(Campaign::class)]
#[CoversClass(Npc::class)]
#[CoversClass(Thread::class)]
#[CoversClass(NpcId::class)]
#[CoversClass(ThreadId::class)]
#[CoversClass(ItemRules::class)]
#[CoversClass(CampaignLimitReached::class)]
final class CampaignNpcsAndThreadsTest extends TestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], tags: [new Tag('ally', 'Ally'), new Tag('rival', 'Rival'), new Tag('guard', 'Guard')]);
    }

    #[Test]
    public function aNewCampaignHoldsNoNpcsOrThreads(): void
    {
        $campaign = $this->campaign();

        self::assertSame([[], []], [$campaign->npcs(), $campaign->threads()]);
        self::assertNull($campaign->npc(NpcId::fromString('npc-1')));
    }

    #[Test]
    public function anNpcIsAddedOpenWithTrimmedValuesAndThePlayersSpelling(): void
    {
        $campaign = $this->campaign();

        $npc = $campaign->addNpc(NpcId::fromString('npc-1'), '  Mira  ', '  Wants the ledger ', ['ally'], ['  Fence ', 'Night shift'], 2, $this->release);

        self::assertSame(['npc-1', 'Mira', 'Wants the ledger', ['ally'], ['Fence', 'Night shift'], 2, ItemStatus::Open], [$npc->id()->toString(), $npc->name(), $npc->agenda(), $npc->tags(), $npc->freeTags(), $npc->weight(), $npc->status()]);
        self::assertSame([$npc], $campaign->npcs());
        self::assertSame($npc, $campaign->npc(NpcId::fromString('npc-1')));
    }

    #[Test]
    public function aBlankAgendaReadsAsNone(): void
    {
        $npc = $this->campaign()->addNpc(NpcId::fromString('npc-1'), 'Mira', " \n", [], [], 1, $this->release);

        self::assertNull($npc->agenda());
    }

    #[Test]
    public function aThreadIsAddedOpenAndAnEditKeepsItsStatus(): void
    {
        $campaign = $this->campaign();
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Rob the vault', ['ally'], [], 1, $this->release);
        $campaign->closeThread(ThreadId::fromString('thread-1'));

        $edited = $campaign->editThread(ThreadId::fromString('thread-1'), ' The vault job ', ['guard'], ['Heist'], 3, $this->release);

        self::assertSame(['The vault job', ['guard'], ['Heist'], 3, ItemStatus::Closed], [$edited->name(), $edited->tags(), $edited->freeTags(), $edited->weight(), $edited->status()]);
        self::assertSame($edited, $campaign->thread(ThreadId::fromString('thread-1')));
    }

    #[Test]
    public function anEditReplacesEveryEditableFieldAndKeepsTheStatusAndPlace(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', 'Wants the ledger', ['ally'], ['Fence'], 2, $this->release);
        $campaign->addNpc(NpcId::fromString('npc-2'), 'Brann', null, [], [], 1, $this->release);

        $campaign->editNpc(NpcId::fromString('npc-1'), 'Mira Vane', null, [], [], 1, $this->release);

        $mira = $campaign->npcs()[0];
        self::assertSame(['Mira Vane', null, [], [], 1], [$mira->name(), $mira->agenda(), $mira->tags(), $mira->freeTags(), $mira->weight()]);
        self::assertSame(['npc-1', 'npc-2'], array_map(static fn (Npc $npc): string => $npc->id()->toString(), $campaign->npcs()));
    }

    #[Test]
    public function openItemsListFirstThenClosedOnesEachInCreationOrder(): void
    {
        $campaign = $this->campaign();
        foreach (['a', 'b', 'c', 'd'] as $key) {
            $campaign->addNpc(NpcId::fromString($key), strtoupper($key), null, [], [], 1, $this->release);
            $campaign->addThread(ThreadId::fromString($key), strtoupper($key), [], [], 1, $this->release);
        }

        $campaign->closeNpc(NpcId::fromString('a'));
        $campaign->closeNpc(NpcId::fromString('c'));
        $campaign->closeThread(ThreadId::fromString('b'));
        $campaign->reopenThread(ThreadId::fromString('b'));
        $campaign->closeThread(ThreadId::fromString('a'));

        self::assertSame(['b', 'd', 'a', 'c'], array_map(static fn (Npc $npc): string => $npc->id()->toString(), $campaign->npcs()));
        self::assertSame(['b', 'c', 'd', 'a'], array_map(static fn (Thread $thread): string => $thread->id()->toString(), $campaign->threads()));
    }

    #[Test]
    public function closingAndReopeningAreIdempotent(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $this->release);
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Vault', [], [], 1, $this->release);

        $campaign->reopenNpc(NpcId::fromString('npc-1'));
        $campaign->reopenThread(ThreadId::fromString('thread-1'));
        self::assertSame([ItemStatus::Open, ItemStatus::Open], [$campaign->npc(NpcId::fromString('npc-1'))?->status(), $campaign->thread(ThreadId::fromString('thread-1'))?->status()]);

        $campaign->closeNpc(NpcId::fromString('npc-1'));
        $closed = $campaign->npc(NpcId::fromString('npc-1'));
        $campaign->closeNpc(NpcId::fromString('npc-1'));
        $campaign->closeThread(ThreadId::fromString('thread-1'));
        $campaign->closeThread(ThreadId::fromString('thread-1'));

        self::assertSame($closed, $campaign->npc(NpcId::fromString('npc-1')));
        self::assertSame([ItemStatus::Closed, ItemStatus::Closed], [$closed?->status(), $campaign->thread(ThreadId::fromString('thread-1'))?->status()]);
    }

    /**
     * @return iterable<string, array{\Closure(Campaign, GameSystemSnapshot): mixed, class-string<\Throwable>, string}>
     */
    public static function invalidNpcs(): iterable
    {
        $add = static function (array $with): \Closure {
            /** @var array{name: string, agenda: ?string, tags: list<string>, freeTags: list<string>, weight: int} $values */
            $values = [...['name' => 'Mira', 'agenda' => null, 'tags' => [], 'freeTags' => [], 'weight' => 1], ...$with];

            return static fn (Campaign $campaign, GameSystemSnapshot $release): Npc => $campaign->addNpc(NpcId::fromString('npc-1'), $values['name'], $values['agenda'], $values['tags'], $values['freeTags'], $values['weight'], $release);
        };

        yield 'blank name' => [$add(['name' => " \t"]), InvalidNpc::class, 'The name must not be blank.'];
        yield 'name of 101' => [$add(['name' => str_repeat('a', 101)]), InvalidNpc::class, 'The name must be at most 100 characters, got 101.'];
        yield 'agenda of 2001' => [$add(['agenda' => str_repeat('a', 2001)]), InvalidNpc::class, 'The agenda must be at most 2000 characters, got 2001.'];
        yield 'eleven tags' => [$add(['tags' => array_map(static fn (int $i): string => 'tag-'.$i, range(1, 11))]), InvalidNpc::class, 'At most 10 tags are allowed, got 11.'];
        yield 'duplicate tag' => [$add(['tags' => ['ally', 'ally']]), InvalidNpc::class, 'Tag "ally" is listed twice.'];
        yield 'unknown tag' => [$add(['tags' => ['ally', 'villain']]), UnknownReleaseTag::class, 'Tag "villain" not found in the pinned release.'];
        yield 'eleven free tags' => [$add(['freeTags' => array_map(static fn (int $i): string => 'free '.$i, range(1, 11))]), InvalidNpc::class, 'At most 10 free tags are allowed, got 11.'];
        yield 'blank free tag' => [$add(['freeTags' => ['  ']]), InvalidNpc::class, 'A free tag must be 1 to 50 characters, got 0.'];
        yield 'free tag of 51' => [$add(['freeTags' => [str_repeat('a', 51)]]), InvalidNpc::class, 'A free tag must be 1 to 50 characters, got 51.'];
        yield 'free tags differing in case' => [$add(['freeTags' => ['Fence', ' fence']]), InvalidNpc::class, 'Free tag "fence" is listed twice.'];
        yield 'weight 0' => [$add(['weight' => 0]), InvalidNpc::class, 'The weight must be 1 to 3, got 0.'];
        yield 'weight 4' => [$add(['weight' => 4]), InvalidNpc::class, 'The weight must be 1 to 3, got 4.'];
        yield 'blank id' => [static fn (): NpcId => NpcId::fromString(' '), InvalidNpc::class, 'An NPC id must not be blank.'];
    }

    /**
     * @param \Closure(Campaign, GameSystemSnapshot): mixed $act
     * @param class-string<\Throwable>                      $exception
     */
    #[Test]
    #[DataProvider('invalidNpcs')]
    public function anInvalidNpcIsRefusedWithAClearErrorAndNothingChanges(\Closure $act, string $exception, string $message): void
    {
        $campaign = $this->campaign();

        try {
            $act($campaign, $this->release);
            self::fail('The invalid NPC was accepted.');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($exception, $thrown);
            self::assertSame($message, $thrown->getMessage());
        }

        self::assertSame([], $campaign->npcs());
    }

    #[Test]
    public function aLongestValidNpcAndThreadAreAccepted(): void
    {
        $campaign = $this->campaign();
        $tags = array_map(static fn (int $i): string => 'tag-'.$i, range(1, 10));
        $release = new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], tags: array_map(static fn (string $key): Tag => new Tag($key, $key), $tags));
        $freeTags = array_map(static fn (int $i): string => str_repeat('f', 49).$i % 10, range(1, 10));
        $freeTags[0] = str_repeat('g', 50);

        $npc = $campaign->addNpc(NpcId::fromString('npc-1'), str_repeat('n', 100), str_repeat('a', 2000), $tags, $freeTags, 3, $release);
        $thread = $campaign->addThread(ThreadId::fromString('thread-1'), str_repeat('t', 200), $tags, $freeTags, 3, $release);

        self::assertSame([100, 2000, 10, 10, 200], [mb_strlen($npc->name()), mb_strlen((string) $npc->agenda()), \count($npc->tags()), \count($npc->freeTags()), mb_strlen($thread->name())]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, class-string<\Throwable>, string}>
     */
    public static function invalidThreads(): iterable
    {
        yield 'blank name' => [['name' => ''], InvalidThread::class, 'The name must not be blank.'];
        yield 'name of 201' => [['name' => str_repeat('a', 201)], InvalidThread::class, 'The name must be at most 200 characters, got 201.'];
        yield 'duplicate tag' => [['tags' => ['guard', 'guard']], InvalidThread::class, 'Tag "guard" is listed twice.'];
        yield 'unknown tag' => [['tags' => ['villain']], UnknownReleaseTag::class, 'Tag "villain" not found in the pinned release.'];
        yield 'free tags differing in case' => [['freeTags' => ['Heist', 'HEIST']], InvalidThread::class, 'Free tag "HEIST" is listed twice.'];
        yield 'weight 4' => [['weight' => 4], InvalidThread::class, 'The weight must be 1 to 3, got 4.'];
    }

    /**
     * @param array<string, mixed>     $with
     * @param class-string<\Throwable> $exception
     */
    #[Test]
    #[DataProvider('invalidThreads')]
    public function anInvalidThreadIsRefusedWithAClearErrorAndNothingChanges(array $with, string $exception, string $message): void
    {
        $campaign = $this->campaign();

        try {
            /** @var array{name: string, tags: list<string>, freeTags: list<string>, weight: int} $values */
            $values = [...['name' => 'Vault', 'tags' => [], 'freeTags' => [], 'weight' => 1], ...$with];
            $campaign->addThread(ThreadId::fromString('thread-1'), $values['name'], $values['tags'], $values['freeTags'], $values['weight'], $this->release);
            self::fail('The invalid Thread was accepted.');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($exception, $thrown);
            self::assertSame($message, $thrown->getMessage());
        }

        self::assertSame([], $campaign->threads());
    }

    #[Test]
    public function aFailingEditChangesNothing(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', 'Wants the ledger', ['ally'], ['Fence'], 2, $this->release);
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Vault', ['ally'], [], 2, $this->release);
        $before = [$campaign->npcs(), $campaign->threads()];

        foreach ([static fn (Campaign $c, GameSystemSnapshot $r): mixed => $c->editNpc(NpcId::fromString('npc-1'), 'Renamed', null, ['ally', 'villain'], [], 1, $r), static fn (Campaign $c, GameSystemSnapshot $r): mixed => $c->editNpc(NpcId::fromString('npc-1'), 'Renamed', null, ['ally'], [], 9, $r), static fn (Campaign $c, GameSystemSnapshot $r): mixed => $c->editThread(ThreadId::fromString('thread-1'), 'Renamed', ['rival'], ['a', 'A'], 1, $r)] as $edit) {
            try {
                $edit($campaign, $this->release);
                self::fail('The failing edit was accepted.');
            } catch (InvalidNpc|InvalidThread|UnknownReleaseTag) {
            }
        }

        self::assertEquals($before, [$campaign->npcs(), $campaign->threads()]);
    }

    #[Test]
    public function anUnknownIdIsRefusedByEveryCommand(): void
    {
        $campaign = $this->campaign();
        $npc = NpcId::fromString('missing');
        $thread = ThreadId::fromString('missing');

        foreach ([
            [fn (): Npc => $campaign->editNpc($npc, 'Mira', null, [], [], 1, $this->release), UnknownNpc::class, 'NPC "missing" not found.'],
            [static fn () => $campaign->closeNpc($npc), UnknownNpc::class, 'NPC "missing" not found.'],
            [static fn () => $campaign->reopenNpc($npc), UnknownNpc::class, 'NPC "missing" not found.'],
            [fn (): Thread => $campaign->editThread($thread, 'Vault', [], [], 1, $this->release), UnknownThread::class, 'Thread "missing" not found.'],
            [static fn () => $campaign->closeThread($thread), UnknownThread::class, 'Thread "missing" not found.'],
            [static fn () => $campaign->reopenThread($thread), UnknownThread::class, 'Thread "missing" not found.'],
        ] as [$command, $exception, $message]) {
            try {
                $command();
                self::fail('The unknown id was accepted.');
            } catch (\DomainException $thrown) {
                self::assertSame([$exception, $message], [$thrown::class, $thrown->getMessage()]);
            }
        }
    }

    #[Test]
    public function anIdAlreadyInUseIsRefusedAndTheItemKept(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('same'), 'Mira', null, [], [], 1, $this->release);
        $campaign->addThread(ThreadId::fromString('same'), 'Vault', [], [], 1, $this->release);

        try {
            $campaign->addNpc(NpcId::fromString('same'), 'Other', null, [], [], 1, $this->release);
            self::fail('The duplicate NPC id was accepted.');
        } catch (NpcAlreadyExists $exception) {
            self::assertSame('NPC "same" already exists.', $exception->getMessage());
        }

        try {
            $campaign->addThread(ThreadId::fromString('same'), 'Other', [], [], 1, $this->release);
            self::fail('The duplicate Thread id was accepted.');
        } catch (ThreadAlreadyExists $exception) {
            self::assertSame('Thread "same" already exists.', $exception->getMessage());
        }

        self::assertSame(['Mira', 'Vault'], [$campaign->npcs()[0]->name(), $campaign->threads()[0]->name()]);
    }

    #[Test]
    public function aCampaignHoldsAtMost500NpcsAnd500Threads(): void
    {
        $campaign = $this->campaign();
        for ($i = 1; $i <= Campaign::MAX_NPCS; ++$i) {
            $campaign->addNpc(NpcId::fromString('npc-'.$i), 'NPC '.$i, null, [], [], 1, $this->release);
        }

        for ($i = 1; $i <= Campaign::MAX_THREADS; ++$i) {
            $campaign->addThread(ThreadId::fromString('thread-'.$i), 'Thread '.$i, [], [], 1, $this->release);
        }

        try {
            $campaign->addNpc(NpcId::fromString('npc-501'), 'One too many', null, [], [], 1, $this->release);
            self::fail('The 501st NPC was accepted.');
        } catch (CampaignLimitReached $exception) {
            self::assertSame('A campaign holds at most 500 NPCs.', $exception->getMessage());
        }

        try {
            $campaign->addThread(ThreadId::fromString('thread-501'), 'One too many', [], [], 1, $this->release);
            self::fail('The 501st Thread was accepted.');
        } catch (CampaignLimitReached $exception) {
            self::assertSame('A campaign holds at most 500 Threads.', $exception->getMessage());
        }

        self::assertSame([500, 500], [\count($campaign->npcs()), \count($campaign->threads())]);
    }

    #[Test]
    public function aChangeReplacesTheListAndTheItemSoStoredValuesCompareDifferent(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $this->release);
        $before = $campaign->npcs();

        $campaign->closeNpc(NpcId::fromString('npc-1'));

        self::assertNotSame($before, $campaign->npcs());
        self::assertSame(ItemStatus::Open, $before[0]->status());
    }

    #[Test]
    public function anotherReleaseThanThePinnedOneIsRefusedByEveryCommandThatTakesOne(): void
    {
        $other = new GameSystemSnapshot('heist', 'Heist', 2, null, [], [], tags: [new Tag('ally', 'Ally')]);
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $this->release);
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Vault', [], [], 1, $this->release);

        foreach ([
            static fn (): Npc => $campaign->addNpc(NpcId::fromString('npc-2'), 'Brann', null, [], [], 1, $other),
            static fn (): Npc => $campaign->editNpc(NpcId::fromString('npc-1'), 'Renamed', null, [], [], 1, $other),
            static fn (): Thread => $campaign->addThread(ThreadId::fromString('thread-2'), 'Debts', [], [], 1, $other),
            static fn (): Thread => $campaign->editThread(ThreadId::fromString('thread-1'), 'Renamed', [], [], 1, $other),
        ] as $command) {
            try {
                $command();
                self::fail('The release that is not the pinned one was accepted.');
            } catch (\LogicException) {
            }
        }

        self::assertSame([['Mira'], ['Vault']], [array_map(static fn (Npc $npc): string => $npc->name(), $campaign->npcs()), array_map(static fn (Thread $thread): string => $thread->name(), $campaign->threads())]);
    }

    #[Test]
    public function theListsAreRebuiltByReconstituteAsStoredWithoutRecheckingTheirRules(): void
    {
        $npc = Npc::of(NpcId::fromString('npc-1'), 'Mira', null, ['ally'], [], 1, ItemStatus::Closed);
        $thread = Thread::of(ThreadId::fromString('thread-1'), 'Vault', [], [], 3);

        $campaign = Campaign::reconstitute(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable(), [], npcs: [$npc], threads: [$thread]);

        self::assertSame([[$npc], [$thread]], [$campaign->npcs(), $campaign->threads()]);
    }

    #[Test]
    public function reconstituteRefusesAListWithTwoItemsOfTheSameId(): void
    {
        $npc = static fn (string $name): Npc => Npc::of(NpcId::fromString('same'), $name, null, [], [], 1);
        $thread = static fn (string $name): Thread => Thread::of(ThreadId::fromString('same'), $name, [], [], 1);

        foreach ([
            [[$npc('Mira'), $npc('Brann')], [], NpcAlreadyExists::class, 'NPC "same" already exists.'],
            [[], [$thread('Vault'), $thread('Debts')], ThreadAlreadyExists::class, 'Thread "same" already exists.'],
        ] as [$npcs, $threads, $exception, $message]) {
            try {
                $this->rebuild($npcs, $threads);
                self::fail('The duplicate id was accepted.');
            } catch (\DomainException $thrown) {
                self::assertSame([$exception, $message], [$thrown::class, $thrown->getMessage()]);
            }
        }
    }

    #[Test]
    public function openItemCountCountsOpenItemsCarryingAllTheTagsAndIgnoresFreeTags(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('n1'), 'Mira', null, ['ally', 'guard'], [], 1, $this->release);
        $campaign->addNpc(NpcId::fromString('n2'), 'Brann', null, ['ally'], ['guard'], 1, $this->release);
        $campaign->addNpc(NpcId::fromString('n3'), 'Tam', null, ['ally', 'guard', 'rival'], [], 1, $this->release);
        $campaign->addNpc(NpcId::fromString('n4'), 'Nobody', null, [], [], 1, $this->release);
        $campaign->closeNpc(NpcId::fromString('n3'));
        $campaign->addThread(ThreadId::fromString('t1'), 'Vault', ['ally'], [], 1, $this->release);

        self::assertSame(
            [4 - 1, 2, 1, 0, 1, 1, 0],
            [
                $campaign->openItemCount(ItemKind::Npc, []),
                $campaign->openItemCount(ItemKind::Npc, ['ally']),
                $campaign->openItemCount(ItemKind::Npc, ['ally', 'guard']),
                $campaign->openItemCount(ItemKind::Npc, ['rival']),
                $campaign->openItemCount(ItemKind::Thread, []),
                $campaign->openItemCount(ItemKind::Thread, ['ally']),
                $campaign->openItemCount(ItemKind::Thread, ['guard']),
            ],
        );
    }

    /**
     * @param list<Npc>    $npcs
     * @param list<Thread> $threads
     */
    private function rebuild(array $npcs, array $threads): Campaign
    {
        return Campaign::reconstitute(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable(), [], npcs: $npcs, threads: $threads);
    }

    private function campaign(): Campaign
    {
        return Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00'));
    }
}
