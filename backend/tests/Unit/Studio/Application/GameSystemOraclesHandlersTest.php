<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\DeleteLikelihoodOracle;
use App\Studio\Application\DeleteLikelihoodOracleHandler;
use App\Studio\Application\DeleteOracleTable;
use App\Studio\Application\DeleteOracleTableHandler;
use App\Studio\Application\GameSystemOraclesView;
use App\Studio\Application\GetGameSystemOracles;
use App\Studio\Application\GetGameSystemOraclesHandler;
use App\Studio\Application\OracleView;
use App\Studio\Application\OwnedGameSystems;
use App\Studio\Application\SaveLikelihoodOracle;
use App\Studio\Application\SaveLikelihoodOracleHandler;
use App\Studio\Application\SaveOracleTable;
use App\Studio\Application\SaveOracleTableHandler;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\OracleInUse;
use App\Studio\Domain\GameSystem\OracleKeyTaken;
use App\Studio\Domain\GameSystem\OracleNotFound;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\FixedClock;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SaveOracleTableHandler::class)]
#[CoversClass(DeleteOracleTableHandler::class)]
#[CoversClass(SaveLikelihoodOracleHandler::class)]
#[CoversClass(DeleteLikelihoodOracleHandler::class)]
#[CoversClass(GetGameSystemOraclesHandler::class)]
#[CoversClass(OwnedGameSystems::class)]
final class GameSystemOraclesHandlersTest extends TestCase
{
    private InMemoryGameSystemRepository $gameSystems;
    private OwnedGameSystems $owned;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->gameSystems = new InMemoryGameSystemRepository();
        $this->owned = new OwnedGameSystems($this->gameSystems);
        $this->clock = new FixedClock('2026-10-16T10:00:00+00:00');
    }

    #[Test]
    public function theReadModelListsTheOraclesInOrderWithTheirVersionAndUsage(): void
    {
        $this->draft('my-heist');

        $view = $this->oracles('ann', 'my-heist');

        self::assertSame([1, 2], [$view->version, $view->schemaVersion]);
        self::assertSame(['heist-scenes', 'complications', 'escape-routes'], $this->keys($view->tables));
        self::assertSame(['fate'], $this->keys($view->likelihood));
        self::assertSame('Complications', $view->tables[1]->definition['name']);
        self::assertContains('sceneTypes[1].play[1]', $view->tables[1]->usedBy);
        self::assertContains('sceneTypes[3].play[0]', $view->likelihood[0]->usedBy);
    }

    #[Test]
    public function aTableNestingItselfIsNotUsedByItsOwnEntries(): void
    {
        $this->draft('my-journal', 'valid/contract-doc-example');

        $view = $this->oracles('ann', 'my-journal');

        self::assertSame(1, $view->schemaVersion);
        self::assertSame(['weather', 'storm-kind'], $this->keys($view->tables));
        self::assertSame([], $view->tables[0]->usedBy);
        self::assertSame(['oracles.tables[0].entries[1].table'], $view->tables[1]->usedBy);
    }

    #[Test]
    public function aDraftWithoutAnOraclesSectionReadsAsEmpty(): void
    {
        $draft = $this->draft('legacy');
        $document = $draft->document();
        unset($document['oracles']);
        $draft->restore($document, $this->clock->now());

        $view = $this->oracles('ann', 'legacy');

        self::assertSame([[], []], [$view->tables, $view->likelihood]);
    }

    #[Test]
    public function whatIsNotAnOracleWithAKeyIsLeftOutOfTheReadModel(): void
    {
        $draft = $this->draft('broken');
        $draft->restore(ReleaseArrays::with($draft->document(), 'oracles', ['tables' => ['text', ['name' => 'No key'], ['key' => 'ok', 'name' => 'Ok', 'entries' => 7]], 'likelihood' => 'nope']), $this->clock->now());

        $view = $this->oracles('ann', 'broken');

        self::assertSame(['ok'], $this->keys($view->tables));
        self::assertSame([], $view->likelihood);
    }

    #[Test]
    public function aTableIsCreatedRenamedAndDeleted(): void
    {
        $this->draft('my-heist');

        $this->saveTable(null, ['key' => 'rumours', 'name' => 'Rumours', 'entries' => [['text' => 'True'], ['text' => 'False']]]);
        $this->saveTable('rumours', ['key' => 'gossip', 'name' => 'Gossip', 'entries' => [['text' => 'True']]], version: 2);

        self::assertSame(['heist-scenes', 'complications', 'escape-routes', 'gossip'], $this->keys($this->oracles('ann', 'my-heist')->tables));

        (new DeleteOracleTableHandler($this->owned, $this->gameSystems, $this->clock))(new DeleteOracleTable('ann', 'my-heist', 'gossip', 3));

        self::assertSame(['heist-scenes', 'complications', 'escape-routes'], $this->keys($this->oracles('ann', 'my-heist')->tables));
        self::assertEquals($this->clock->now(), $this->gameSystems->ofKey('my-heist')?->updatedAt());
    }

    #[Test]
    public function aLikelihoodOracleIsCreatedRenamedAndDeleted(): void
    {
        $this->draft('my-heist');
        $handler = new SaveLikelihoodOracleHandler($this->owned, $this->gameSystems, $this->clock);

        $handler(new SaveLikelihoodOracle('ann', 'my-heist', null, ['key' => 'gut', 'name' => 'Gut', 'sides' => 100, 'levels' => [['key' => 'even', 'label' => '50/50', 'target' => 50]]], 1));
        $handler(new SaveLikelihoodOracle('ann', 'my-heist', 'gut', ['key' => 'hunch', 'name' => 'Hunch', 'sides' => 100, 'levels' => [['key' => 'even', 'label' => '50/50', 'target' => 50]]], 2));

        self::assertSame(['fate', 'hunch'], $this->keys($this->oracles('ann', 'my-heist')->likelihood));

        (new DeleteLikelihoodOracleHandler($this->owned, $this->gameSystems, $this->clock))(new DeleteLikelihoodOracle('ann', 'my-heist', 'hunch', 3));

        self::assertSame(['fate'], $this->keys($this->oracles('ann', 'my-heist')->likelihood));
    }

    #[Test]
    public function aSaveByAClientThatReadBeforeAnotherSavedIsRefused(): void
    {
        $draft = $this->draft('my-heist');
        $readByBoth = $this->oracles('ann', 'my-heist')->version;

        $this->saveTable(null, ['key' => 'rumours', 'name' => 'Rumours', 'entries' => [['text' => 'True']]], version: $readByBoth);
        $before = $draft->document();

        $this->expectRefusal(GameSystemModifiedConcurrently::class, $draft, $before, fn () => $this->saveTable(null, ['key' => 'gossip', 'name' => 'Gossip', 'entries' => [['text' => 'True']]], version: $readByBoth));
        self::assertSame($readByBoth + 1, $this->oracles('ann', 'my-heist')->version);
    }

    /**
     * @return iterable<string, array{\Closure(self): void}>
     */
    public static function commands(): iterable
    {
        $table = ['key' => 'rumours', 'name' => 'Rumours', 'entries' => [['text' => 'True']]];
        $oracle = ['key' => 'gut', 'name' => 'Gut', 'sides' => 100, 'levels' => [['key' => 'even', 'label' => '50/50', 'target' => 50]]];

        yield 'save a table' => [static fn (self $t, string $user = 'ann', int $version = 1) => $t->saveTable(null, $table, $user, $version)];
        yield 'delete a table' => [static fn (self $t, string $user = 'ann', int $version = 1) => (new DeleteOracleTableHandler($t->owned, $t->gameSystems, $t->clock))(new DeleteOracleTable($user, 'my-heist', 'escape-routes', $version))];
        yield 'save a likelihood oracle' => [static fn (self $t, string $user = 'ann', int $version = 1) => (new SaveLikelihoodOracleHandler($t->owned, $t->gameSystems, $t->clock))(new SaveLikelihoodOracle($user, 'my-heist', null, $oracle, $version))];
        yield 'delete a likelihood oracle' => [static fn (self $t, string $user = 'ann', int $version = 1) => (new DeleteLikelihoodOracleHandler($t->owned, $t->gameSystems, $t->clock))(new DeleteLikelihoodOracle($user, 'my-heist', 'fate', $version))];
    }

    /**
     * @param \Closure(self, string, int): void $command
     */
    #[Test]
    #[DataProvider('commands')]
    public function aStaleExpectedVersionIsRefusedAndChangesNothing(\Closure $command): void
    {
        $draft = $this->draft('my-heist');
        $before = $draft->document();

        $this->expectRefusal(GameSystemModifiedConcurrently::class, $draft, $before, fn () => $command($this, 'ann', 2));
    }

    /**
     * @param \Closure(self, string, int): void $command
     */
    #[Test]
    #[DataProvider('commands')]
    public function anotherOwnersDraftIsNotFound(\Closure $command): void
    {
        $draft = $this->draft('my-heist');
        $before = $draft->document();

        $this->expectRefusal(GameSystemNotFound::class, $draft, $before, fn () => $command($this, 'bob', 1));
    }

    #[Test]
    public function theReadModelOfAnotherOwnersOrAMissingDraftIsNotFound(): void
    {
        $this->draft('my-heist');

        $refused = [];
        foreach ([['bob', 'my-heist'], ['ann', 'nothing']] as [$user, $key]) {
            try {
                $this->oracles($user, $key);
            } catch (GameSystemNotFound) {
                $refused[] = $user;
            }
        }

        self::assertSame(['bob', 'ann'], $refused);
    }

    /**
     * @return iterable<string, array{\Closure(self): void, class-string<\Throwable>}>
     */
    public static function refusedCommands(): iterable
    {
        $table = ['key' => 'rumours', 'name' => 'Rumours', 'entries' => [['text' => 'True']]];

        yield 'an invalid table' => [static fn (self $t) => $t->saveTable(null, [...$table, 'entries' => []]), InvalidReleaseContent::class];
        yield 'a taken key' => [static fn (self $t) => $t->saveTable(null, [...$table, 'key' => 'fate']), OracleKeyTaken::class];
        yield 'a missing original key' => [static fn (self $t) => $t->saveTable('nowhere', $table), OracleNotFound::class];
        yield 'renaming a referenced table' => [static fn (self $t) => $t->saveTable('complications', [...$t->storedTable('complications'), 'key' => 'troubles']), OracleInUse::class];
        yield 'deleting a referenced table' => [static fn (self $t) => (new DeleteOracleTableHandler($t->owned, $t->gameSystems, $t->clock))(new DeleteOracleTable('ann', 'my-heist', 'complications', 1)), OracleInUse::class];
        yield 'deleting a missing table' => [static fn (self $t) => (new DeleteOracleTableHandler($t->owned, $t->gameSystems, $t->clock))(new DeleteOracleTable('ann', 'my-heist', 'nowhere', 1)), OracleNotFound::class];
        yield 'deleting a referenced likelihood oracle' => [static fn (self $t) => (new DeleteLikelihoodOracleHandler($t->owned, $t->gameSystems, $t->clock))(new DeleteLikelihoodOracle('ann', 'my-heist', 'fate', 1)), OracleInUse::class];
        yield 'an invalid likelihood oracle' => [static fn (self $t) => (new SaveLikelihoodOracleHandler($t->owned, $t->gameSystems, $t->clock))(new SaveLikelihoodOracle('ann', 'my-heist', null, ['key' => 'gut', 'name' => 'Gut'], 1)), InvalidReleaseContent::class];
    }

    /**
     * @param \Closure(self): void     $command
     * @param class-string<\Throwable> $refusal
     */
    #[Test]
    #[DataProvider('refusedCommands')]
    public function aRefusedCommandLeavesTheStoredDocumentUntouched(\Closure $command, string $refusal): void
    {
        $draft = $this->draft('my-heist');
        $before = $draft->document();
        $updatedAt = $draft->updatedAt();

        $this->expectRefusal($refusal, $draft, $before, fn () => $command($this));

        self::assertEquals($updatedAt, $draft->updatedAt());
    }

    /**
     * @param class-string<\Throwable> $refusal
     * @param array<string, mixed>     $before
     */
    private function expectRefusal(string $refusal, GameSystem $draft, array $before, \Closure $command): void
    {
        $version = $draft->version();

        try {
            $command();
            self::fail('Expected '.$refusal);
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($refusal, $thrown);
        }

        self::assertSame($before, $draft->document());
        self::assertSame($version, $draft->version());
        ReleaseContent::fromArray($draft->document());
    }

    /**
     * @param array<mixed> $table
     */
    public function saveTable(?string $originalKey, array $table, string $user = 'ann', int $version = 1): void
    {
        (new SaveOracleTableHandler($this->owned, $this->gameSystems, $this->clock))(new SaveOracleTable($user, 'my-heist', $originalKey, $table, $version));
    }

    /**
     * @return array<mixed>
     */
    public function storedTable(string $key): array
    {
        foreach ($this->oracles('ann', 'my-heist')->tables as $table) {
            if ($table->key === $key) {
                return $table->definition;
            }
        }

        throw new \LogicException('No table '.$key);
    }

    private function draft(string $key, string $fixture = 'valid/v2-every-part'): GameSystem
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture($fixture))->toArray();
        $draft = GameSystem::createFromRelease(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61'), $key, 'Draft', null, 'ann', $source, $this->clock->now());
        $this->gameSystems->add($draft);

        return $draft;
    }

    private function oracles(string $user, string $key): GameSystemOraclesView
    {
        return (new GetGameSystemOraclesHandler($this->owned))(new GetGameSystemOracles($user, $key));
    }

    /**
     * @param list<OracleView> $views
     *
     * @return list<string>
     */
    private function keys(array $views): array
    {
        return array_map(static fn (OracleView $view): string => $view->key, $views);
    }
}
